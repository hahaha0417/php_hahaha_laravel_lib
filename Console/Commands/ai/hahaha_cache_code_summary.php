<?php

namespace L_Lib\Console\Commands\ai;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use SplFileInfo;

#[Signature('app:hahaha-cache-code-summary {--output= : Output file path}', aliases: ['l_lib:app:hahaha-cache-code-summary'])]
#[Description('為 AI 助手快取精簡程式碼摘要')]
class hahaha_cache_code_summary extends Command
{
    public const DEFAULT_OUTPUT = 'storage/app/ai-context/code-summary.md';
    public const META_SUFFIX = '.meta.json';

    public const EXCLUDED_PREFIXES = [
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

    public const INCLUDED_PREFIXES = [
        'app/',
        'bootstrap/',
        'config/',
        'database/',
        'library/hahaha_laravel_lib/',
        'resources/',
        'routes/',
        'tests/',
    ];

    public readonly string $base_path_normalized_;

    public function __construct(public readonly Filesystem $files_)
    {
        parent::__construct();
        $this->base_path_normalized_ = str_replace('\\', '/', base_path());
    }

    public function handle(): int
    {
        $output_path_ = $this->Resolve_Output_Path((string) $this->option('output'));
        $relevant_files_ = $this->Collect_Relevant_Files();
        $fingerprint_ = $this->Build_Fingerprint($relevant_files_);

        if ($this->Is_Fingerprint_Unchanged($output_path_, $fingerprint_)) {
            $this->components->info(sprintf('程式碼未變更，略過重建：%s', $this->Display_Path($output_path_)));

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
            $type_ = $this->Detect_Type($path_);
            $lines_[] = sprintf('- %s [%s] (%d bytes)', $path_, $type_, $file_->getSize());
        }

        $this->files_->put($output_path_, implode(PHP_EOL, $lines_).PHP_EOL);
        $this->Write_Fingerprint($output_path_, $fingerprint_);

        $this->components->info(sprintf('程式碼摘要已輸出：%s', $this->Display_Path($output_path_)));

        return self::SUCCESS;
    }

    /** @return array<int, array{file: SplFileInfo, path: string}> */
    public function Collect_Relevant_Files(): array
    {
        $items_ = [];

        foreach ($this->files_->allFiles(base_path()) as $file_) {
            $path_ = $this->Relative_Path($file_);

            if (! $this->Is_Relevant_Path($path_)) {
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

    public function Is_Relevant_Path(string $path): bool
    {
        $normalized_ = str_replace('\\', '/', $path);

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

    public function Detect_Type(string $path): string
    {
        return match (true) {
            str_ends_with($path, '.php') => 'PHP',
            str_ends_with($path, '.blade.php') => 'Blade',
            str_ends_with($path, '.json') => 'JSON',
            str_ends_with($path, '.xml') => 'XML',
            str_ends_with($path, '.js') => 'JavaScript',
            str_ends_with($path, '.ts') => 'TypeScript',
            str_ends_with($path, '.vue') => 'Vue',
            default => 'File',
        };
    }

    /** @param array<int, array{file: SplFileInfo, path: string}> $relevant_files_ */
    public function Build_Fingerprint(array $relevant_files): string
    {
        $parts_ = [];

        foreach ($relevant_files as $item_) {
            $file_ = $item_['file'];
            $parts_[] = sprintf('%s|%d|%d', $item_['path'], $file_->getMTime(), $file_->getSize());
        }

        return hash('sha256', implode("\n", $parts_));
    }

    public function Is_Fingerprint_Unchanged(string $output_path, string $fingerprint): bool
    {
        $meta_path_ = $output_path.self::META_SUFFIX;

        if (! $this->files_->exists($output_path) || ! $this->files_->exists($meta_path_)) {
            return false;
        }

        $meta_ = json_decode($this->files_->get($meta_path_), true);

        return is_array($meta_) && ($meta_['fingerprint'] ?? null) === $fingerprint;
    }

    public function Write_Fingerprint(string $output_path, string $fingerprint): void
    {
        $meta_path_ = $output_path.self::META_SUFFIX;

        $this->files_->put($meta_path_, json_encode([
            'fingerprint' => $fingerprint,
            'updated_at' => Carbon::now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL);
    }

    public function Resolve_Output_Path(string $output_path): string
    {
        $normalized_ = trim($output_path);

        if ($normalized_ === '') {
            $normalized_ = self::DEFAULT_OUTPUT;
        }

        return $this->Is_Absolute_Path($normalized_) ? $normalized_ : base_path($normalized_);
    }

    public function Relative_Path(SplFileInfo $file): string
    {
        return ltrim(str_replace($this->base_path_normalized_, '', str_replace('\\', '/', $file->getPathname())), '/');
    }

    public function Display_Path(string $path): string
    {
        $base_ = str_replace('\\', '/', base_path());
        $normalized_ = str_replace('\\', '/', $path);

        return str_starts_with($normalized_, $base_.'/') ? substr($normalized_, strlen($base_) + 1) : $normalized_;
    }

    public function Is_Absolute_Path(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path) === 1;
    }
}
