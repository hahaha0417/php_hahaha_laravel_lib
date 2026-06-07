<?php

namespace L_Lib\Console\Commands\ai;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use SplFileInfo;

#[Signature('app:hahaha-cache-project-structure {--output= : Output file path}', aliases: ['l_lib:app:hahaha-cache-project-structure'])]
#[Description('為 AI 助手快取可讀的專案結構快照')]
class hahaha_cache_project_structure extends Command
{
    private const DEFAULT_OUTPUT = 'storage/app/ai-context/project-structure.md';
    private const META_SUFFIX = '.meta.json';

    private const EXCLUDED_PREFIXES = [
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

    private readonly string $base_path_normalized_;

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
        $this->base_path_normalized_ = str_replace('\\', '/', base_path());
    }

    public function handle(): int
    {
        $output_path_ = $this->resolveOutputPath((string) $this->option('output'));
        $scan_result_ = $this->collectPathsAndFingerprintParts();
        $paths_ = $scan_result_['paths'];
        $fingerprint_ = hash('sha256', implode("\n", $scan_result_['parts']));

        if ($this->isFingerprintUnchanged($output_path_, $fingerprint_)) {
            $this->components->info(sprintf('程式碼未變更，略過重建：%s', $this->displayPath($output_path_)));

            return self::SUCCESS;
        }

        $lines_ = [
            '# Project Structure',
            '',
            'Generated at: '.Carbon::now()->toDateTimeString(),
            '',
            '```text',
            '.',
        ];

        foreach ($paths_ as $path_) {
            $lines_[] = '|-- '.$path_;
        }

        $lines_[] = '```';
        $lines_[] = '';

        $this->files->put($output_path_, implode(PHP_EOL, $lines_));
        $this->writeFingerprint($output_path_, $fingerprint_);

        $this->components->info(sprintf('專案結構快照已輸出：%s', $this->displayPath($output_path_)));

        return self::SUCCESS;
    }

    /** @return array{paths: array<int, string>, parts: array<int, string>} */
    private function collectPathsAndFingerprintParts(): array
    {
        $paths_ = [];
        $parts_ = [];

        foreach ($this->files->allFiles(base_path()) as $file_) {
            $path_ = $this->relativePath($file_);

            if ($this->isExcluded($path_)) {
                continue;
            }

            $paths_[] = $path_;
            $parts_[] = sprintf('%s|%d|%d', $path_, $file_->getMTime(), $file_->getSize());
        }

        sort($paths_);
        sort($parts_);

        return [
            'paths' => $paths_,
            'parts' => $parts_,
        ];
    }

    private function isExcluded(string $path_): bool
    {
        $normalized_ = str_replace('\\', '/', $path_);

        foreach (self::EXCLUDED_PREFIXES as $excluded_) {
            if (str_starts_with($normalized_, $excluded_)) {
                return true;
            }
        }

        return false;
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
