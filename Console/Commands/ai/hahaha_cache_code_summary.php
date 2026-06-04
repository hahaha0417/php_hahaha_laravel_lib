<?php

namespace L_Lib\Console\Commands\ai;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use SplFileInfo;

#[Signature('app:hahaha-cache-code-summary {--output= : Output file path}')]
#[Description('為 AI 助手快取精簡程式碼摘要')]
class hahaha_cache_code_summary extends Command
{
    private const DEFAULT_OUTPUT = 'storage/app/ai-context/code-summary.md';
    private const META_SUFFIX = '.meta.json';

    private const EXCLUDED_PREFIXES = [
        '.codex/',
        '.git/',
        'bootstrap/cache/',
        'node_modules/',
        'public/build/',
        'public/hot/',
        'storage/framework/',
        'storage/logs/',
        'storage/pail/',
        'vendor/',
    ];

    private const INCLUDED_PREFIXES = [
        'app/',
        'bootstrap/',
        'config/',
        'database/',
        'library/hahaha_laravel_lib/',
        'resources/',
        'routes/',
        'tests/',
    ];

    private readonly string $base_path_normalized_;

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
        $this->base_path_normalized_ = str_replace('\\', '/', base_path());
    }

    public function handle(): int
    {
        $output_path_ = $this->resolveOutputPath((string) $this->option('output'));
        $relevant_files_ = $this->collectRelevantFiles();
        $fingerprint_ = $this->buildFingerprint($relevant_files_);

        if ($this->isFingerprintUnchanged($output_path_, $fingerprint_)) {
            $this->components->info(sprintf('程式碼未變更，略過重建：%s', $this->displayPath($output_path_)));

            return self::SUCCESS;
        }

        $lines_ = [
            '# Code Summary',
            '',
            'Generated at: '.Carbon::now()->toDateTimeString(),
            'Root: '.base_path(),
            '',
        ];

        foreach ($relevant_files_ as $item_) {
            $file_ = $item_['file'];
            $path_ = $item_['path'];
            $type_ = $this->detectType($path_);
            $lines_[] = sprintf('- %s [%s] (%d bytes)', $path_, $type_, $file_->getSize());
        }

        $this->files->put($output_path_, implode(PHP_EOL, $lines_).PHP_EOL);
        $this->writeFingerprint($output_path_, $fingerprint_);

        $this->components->info(sprintf('程式碼摘要已輸出：%s', $this->displayPath($output_path_)));

        return self::SUCCESS;
    }

    /** @return array<int, array{file: SplFileInfo, path: string}> */
    private function collectRelevantFiles(): array
    {
        $items_ = [];

        foreach ($this->files->allFiles(base_path()) as $file_) {
            $path_ = $this->relativePath($file_);

            if (! $this->isRelevantPath($path_)) {
                continue;
            }

            $items_[] = [
                'file' => $file_,
                'path' => $path_,
            ];
        }

        usort($items_, static fn (array $a_, array $b_): int => strcmp($a_['path'], $b_['path']));

        return $items_;
    }

    private function isRelevantPath(string $path_): bool
    {
        $normalized_ = str_replace('\\', '/', $path_);

        foreach (self::EXCLUDED_PREFIXES as $excluded_) {
            if (str_starts_with($normalized_, $excluded_)) {
                return false;
            }
        }

        foreach (self::INCLUDED_PREFIXES as $included_) {
            if (str_starts_with($normalized_, $included_)) {
                return true;
            }
        }

        return in_array($normalized_, ['composer.json', 'package.json', 'phpunit.xml', 'artisan', 'vite.config.js'], true);
    }

    private function detectType(string $path_): string
    {
        return match (true) {
            str_ends_with($path_, '.php') => 'PHP',
            str_ends_with($path_, '.blade.php') => 'Blade',
            str_ends_with($path_, '.json') => 'JSON',
            str_ends_with($path_, '.xml') => 'XML',
            str_ends_with($path_, '.js') => 'JavaScript',
            str_ends_with($path_, '.ts') => 'TypeScript',
            str_ends_with($path_, '.vue') => 'Vue',
            default => 'File',
        };
    }

    /** @param array<int, array{file: SplFileInfo, path: string}> $relevant_files_ */
    private function buildFingerprint(array $relevant_files_): string
    {
        $parts_ = [];

        foreach ($relevant_files_ as $item_) {
            $file_ = $item_['file'];
            $parts_[] = sprintf('%s|%d|%d', $item_['path'], $file_->getMTime(), $file_->getSize());
        }

        return hash('sha256', implode("\n", $parts_));
    }

    private function isFingerprintUnchanged(string $output_path_, string $fingerprint_): bool
    {
        $meta_path_ = $output_path_.self::META_SUFFIX;

        if (! $this->files->exists($output_path_) || ! $this->files->exists($meta_path_)) {
            return false;
        }

        $meta_ = json_decode($this->files->get($meta_path_), true);

        return is_array($meta_) && ($meta_['fingerprint'] ?? null) === $fingerprint_;
    }

    private function writeFingerprint(string $output_path_, string $fingerprint_): void
    {
        $meta_path_ = $output_path_.self::META_SUFFIX;

        $this->files->put($meta_path_, json_encode([
            'fingerprint' => $fingerprint_,
            'updated_at' => Carbon::now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL);
    }

    private function resolveOutputPath(string $output_path_): string
    {
        $normalized_ = trim($output_path_);

        if ($normalized_ === '') {
            $normalized_ = self::DEFAULT_OUTPUT;
        }

        return $this->isAbsolutePath($normalized_) ? $normalized_ : base_path($normalized_);
    }

    private function relativePath(SplFileInfo $file_): string
    {
        return ltrim(str_replace($this->base_path_normalized_, '', str_replace('\\', '/', $file_->getPathname())), '/');
    }

    private function displayPath(string $path_): string
    {
        $base_ = str_replace('\\', '/', base_path());
        $normalized_ = str_replace('\\', '/', $path_);

        return str_starts_with($normalized_, $base_.'/') ? substr($normalized_, strlen($base_) + 1) : $normalized_;
    }

    private function isAbsolutePath(string $path_): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path_) === 1;
    }
}
