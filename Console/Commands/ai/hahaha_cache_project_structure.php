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
    public const DEFAULT_OUTPUT = 'storage/app/ai-context/project-structure.md';
    public const META_SUFFIX = '.meta.json';

    public const EXCLUDED_PREFIXES = [
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

    public readonly string $base_path_normalized_;

    public function __construct(public readonly Filesystem $files_)
    {
        parent::__construct();
        $this->base_path_normalized_ = str_replace('\\', '/', base_path());
    }

    public function handle(): int
    {
        $output_path_ = $this->Resolve_Output_Path((string) $this->option('output'));
        $scan_result_ = $this->Collect_Paths_And_Fingerprint_Parts();
        $paths_ = $scan_result_['paths'];
        $fingerprint_ = hash('sha256', implode("\n", $scan_result_['parts']));

        if ($this->Is_Fingerprint_Unchanged($output_path_, $fingerprint_)) {
            $this->components->info(sprintf('程式碼未變更，略過重建：%s', $this->Display_Path($output_path_)));

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

        $this->files_->put($output_path_, implode(PHP_EOL, $lines_));
        $this->Write_Fingerprint($output_path_, $fingerprint_);

        $this->components->info(sprintf('專案結構快照已輸出：%s', $this->Display_Path($output_path_)));

        return self::SUCCESS;
    }

    /** @return array{paths: array<int, string>, parts: array<int, string>} */
    public function Collect_Paths_And_Fingerprint_Parts(): array
    {
        $paths_ = [];
        $parts_ = [];

        foreach ($this->files_->allFiles(base_path()) as $file_) {
            $path_ = $this->Relative_Path($file_);

            if ($this->Is_Excluded($path_)) {
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

    public function Is_Excluded(string $path): bool
    {
        $normalized_ = str_replace('\\', '/', $path);

        foreach (self::EXCLUDED_PREFIXES as $excluded_) {
            if (str_starts_with($normalized_, $excluded_)) {
                return true;
            }
        }

        return false;
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
