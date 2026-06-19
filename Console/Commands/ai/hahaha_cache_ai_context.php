<?php

namespace L_Lib\Console\Commands\ai;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

#[Signature('app:hahaha-cache-ai-context
    {--output-dir=storage/app/ai-context : Directory used to store AI context cache files}
    {--with-tests : Generate tests.md only when it is explicitly needed}', aliases: ['l_lib:app:hahaha-cache-ai-context'])]
#[Description('為 AI 助手快取多份專案上下文檔案')]
class hahaha_cache_ai_context extends Command
{
    public const DEFAULT_OUTPUT_DIR = 'storage/app/ai-context';
    public const META_FILE = '.hahaha_cache_meta.json';

    /** @var array<int, SplFileInfo>|null */
    public ?array $all_files_cache_ = null;

    /** @var array<int, SplFileInfo>|null */
    public ?array $relevant_context_files_cache_ = null;

    public function __construct(
        public readonly Filesystem $files_,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $output_dir_ = $this->Resolve_Output_Dir((string) $this->option('output-dir'));
        $this->files_->ensureDirectoryExists($output_dir_);

        $fingerprint_ = $this->Build_Fingerprint($output_dir_);

        if ($this->Is_Fingerprint_Unchanged($output_dir_, $fingerprint_)) {
            $this->components->info(sprintf('程式碼未變更，略過重建：%s', $this->Display_Path($output_dir_)));

            return self::SUCCESS;
        }

        $this->Write_File($output_dir_, 'routes.md', $this->Render_Routes_Summary());
        $this->Write_File($output_dir_, 'database-schema.md', $this->Render_Database_Schema_Summary());
        $this->Write_File($output_dir_, 'config.md', $this->Render_Config_Summary());
        $this->Write_File($output_dir_, 'packages.md', $this->Render_Package_Summary());
        $this->Tests_Summary_Write($output_dir_);
        $this->Write_File($output_dir_, 'recent-changes.md', $this->Render_Recent_Changes_Summary());
        $this->Write_File($output_dir_, 'ownership-map.md', $this->Render_Ownership_Map());
        $this->Write_File($output_dir_, 'php-symbols.md', $this->Render_Php_Symbols_Summary());
        $this->Write_Fingerprint($output_dir_, $fingerprint_);

        $this->components->info(sprintf('AI 上下文快取已輸出：%s', $this->Display_Path($output_dir_)));

        return self::SUCCESS;
    }

    public function Render_Routes_Summary(): string
    {
        $routes_ = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route_) => [
                'methods' => implode('|', array_values(array_filter($route_->methods(), static fn (string $method_): bool => $method_ !== 'HEAD'))),
                'uri' => $route_->uri(),
                'name' => $route_->getName() ?? '-',
                'action' => $route_->getActionName(),
            ])
            ->sortBy('uri')
            ->values();

        $lines_ = [
            '# Routes',
            '',
            'Generated at: '.Carbon::now()->toDateTimeString(),
            'Count: '.$routes_->count(),
            '',
        ];

        foreach ($routes_ as $route_) {
            $lines_[] = sprintf('- [%s] %s (%s) => %s', $route_['methods'], $route_['uri'], $route_['name'], $route_['action']);
        }

        return implode(PHP_EOL, $lines_).PHP_EOL;
    }

    public function Render_Database_Schema_Summary(): string
    {
        try {
            $tables_ = Schema::getTables();
            $database_name_ = DB::getDatabaseName();
        } catch (Throwable $throwable_) {
            return implode(PHP_EOL, [
                '# Database Schema',
                '',
                'Generated at: '.Carbon::now()->toDateTimeString(),
                'Status: unavailable',
                'Reason: '.$throwable_->getMessage(),
                '',
            ]).PHP_EOL;
        }

        $lines_ = [
            '# Database Schema',
            '',
            'Generated at: '.Carbon::now()->toDateTimeString(),
            'Database: '.$database_name_,
            'Table count: '.count($tables_),
            '',
        ];

        foreach ($tables_ as $table_) {
            $table_name_ = $table_['name'] ?? $table_['schema_qualified_name'] ?? 'unknown';
            $lines_[] = '## '.$table_name_;

            foreach (Schema::getColumns($table_name_) as $column_) {
                $lines_[] = sprintf('- %s: %s', $column_['name'], $column_['type_name']);
            }

            $lines_[] = '';
        }

        return implode(PHP_EOL, $lines_).PHP_EOL;
    }

    public function Render_Config_Summary(): string
    {
        $lines_ = [
            '# Config',
            '',
            'Generated at: '.Carbon::now()->toDateTimeString(),
            '- app.name: '.(string) config('app.name'),
            '- app.env: '.(string) config('app.env'),
            '- app.debug: '.(config('app.debug') ? 'true' : 'false'),
            '- database.default: '.(string) config('database.default'),
            '- cache.default: '.(string) config('cache.default'),
            '- queue.default: '.(string) config('queue.default'),
            '',
        ];

        return implode(PHP_EOL, $lines_).PHP_EOL;
    }

    public function Render_Package_Summary(): string
    {
        $composer_ = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $package_json_ = json_decode((string) file_get_contents(base_path('package.json')), true);

        $lines_ = [
            '# Packages',
            '',
            'Generated at: '.Carbon::now()->toDateTimeString(),
            '- composer require: '.implode(', ', array_keys($composer_['require'] ?? [])),
            '- composer require-dev: '.implode(', ', array_keys($composer_['require-dev'] ?? [])),
            '- npm devDependencies: '.implode(', ', array_keys($package_json_['devDependencies'] ?? [])),
            '',
        ];

        return implode(PHP_EOL, $lines_).PHP_EOL;
    }

    public function Render_Test_Summary(): string
    {
        $test_files_ = collect($this->All_Files_Cached())
            ->filter(static fn (SplFileInfo $file_): bool => $file_->getExtension() === 'php')
            ->filter(fn (SplFileInfo $file_): bool => str_starts_with($this->Relative_Path($file_), 'tests/'))
            ->map(fn (SplFileInfo $file_): string => $this->Relative_Path($file_))
            ->sort()
            ->values();

        $lines_ = [
            '# Tests',
            '',
            'Generated at: '.Carbon::now()->toDateTimeString(),
            'Count: '.$test_files_->count(),
            '',
        ];

        foreach ($test_files_ as $file_) {
            $lines_[] = '- '.$file_;
        }

        return implode(PHP_EOL, $lines_).PHP_EOL;
    }

    public function Tests_Summary_Write(string $output_dir): void
    {
        $tests_summary_path_ = $output_dir.DIRECTORY_SEPARATOR.'tests.md';

        if (! (bool) $this->option('with-tests')) {
            if ($this->files_->exists($tests_summary_path_)) {
                $this->files_->delete($tests_summary_path_);
            }

            return;
        }

        $this->Write_File($output_dir, 'tests.md', $this->Render_Test_Summary());
    }

    public function Render_Recent_Changes_Summary(): string
    {
        $files_ = collect($this->Relevant_Context_Files_Cached())
            ->sortByDesc(fn (SplFileInfo $file_): int => $file_->getMTime())
            ->take(20)
            ->values();

        $lines_ = [
            '# Recent Changes',
            '',
            'Generated at: '.Carbon::now()->toDateTimeString(),
            '',
        ];

        foreach ($files_ as $file_) {
            $lines_[] = sprintf('- %s (%s)', $this->Relative_Path($file_), Carbon::createFromTimestamp($file_->getMTime())->toDateTimeString());
        }

        return implode(PHP_EOL, $lines_).PHP_EOL;
    }

    public function Render_Ownership_Map(): string
    {
        $files_ = collect($this->Relevant_Context_Files_Cached())
            ->map(fn (SplFileInfo $file_): string => $this->Relative_Path($file_))
            ->values();

        $bucket_counts_ = [
            'app' => 0,
            'library' => 0,
            'config' => 0,
            'database' => 0,
            'resources' => 0,
            'routes' => 0,
            'tests' => 0,
            'other' => 0,
        ];

        foreach ($files_ as $path_) {
            $bucket_ = match (true) {
                str_starts_with($path_, 'app/') => 'app',
                str_starts_with($path_, 'library/hahaha_laravel_lib/') => 'library',
                str_starts_with($path_, 'config/') => 'config',
                str_starts_with($path_, 'database/') => 'database',
                str_starts_with($path_, 'resources/') => 'resources',
                str_starts_with($path_, 'routes/') => 'routes',
                str_starts_with($path_, 'tests/') => 'tests',
                default => 'other',
            };

            $bucket_counts_[$bucket_]++;
        }

        $lines_ = [
            '# Ownership Map',
            '',
            'Generated at: '.Carbon::now()->toDateTimeString(),
            '',
        ];

        foreach ($bucket_counts_ as $bucket_ => $count_) {
            $lines_[] = sprintf('- %s: %d', $bucket_, $count_);
        }

        return implode(PHP_EOL, $lines_).PHP_EOL;
    }

    public function Render_Php_Symbols_Summary(): string
    {
        $php_files_ = collect($this->Relevant_Context_Files_Cached())
            ->filter(fn (SplFileInfo $file_): bool => str_ends_with($this->Relative_Path($file_), '.php'))
            ->sortBy(fn (SplFileInfo $file_): string => $this->Relative_Path($file_))
            ->values();

        $lines_ = [
            '# PHP Symbols',
            '',
            'Generated at: '.Carbon::now()->toDateTimeString(),
            '',
        ];

        foreach ($php_files_ as $file_) {
            $contents_ = $this->files_->get($file_->getPathname());

            if (preg_match('/\b(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents_, $match_) !== 1) {
                continue;
            }

            $lines_[] = sprintf('- %s => %s %s', $this->Relative_Path($file_), $match_[1], $match_[2]);
        }

        return implode(PHP_EOL, $lines_).PHP_EOL;
    }

    public function Write_File(string $output_dir, string $filename, string $contents): void
    {
        $path_ = $output_dir.DIRECTORY_SEPARATOR.$filename;

        if ($this->files_->exists($path_) && $this->files_->get($path_) === $contents) {
            return;
        }

        $this->files_->put($path_, $contents);
    }

    public function Build_Fingerprint(string $output_dir): string
    {
        $parts_ = [];
        $output_dir_normalized_ = str_replace('\\', '/', rtrim($output_dir, '\\/'));

        foreach ($this->Relevant_Context_Files_Cached() as $file_) {
            $relative_path_ = $this->Relative_Path($file_);

            $full_path_normalized_ = str_replace('\\', '/', $file_->getPathname());
            if (str_starts_with($full_path_normalized_, $output_dir_normalized_.'/')) {
                continue;
            }

            $parts_[] = sprintf('%s|%d|%d', $relative_path_, $file_->getMTime(), $file_->getSize());
        }

        sort($parts_);

        return hash('sha256', implode("\n", $parts_));
    }

    public function Is_Fingerprint_Unchanged(string $output_dir, string $fingerprint): bool
    {
        $meta_path_ = rtrim($output_dir, '\\/').DIRECTORY_SEPARATOR.self::META_FILE;

        if (! $this->files_->exists($meta_path_)) {
            return false;
        }

        $meta_ = json_decode($this->files_->get($meta_path_), true);

        return is_array($meta_) && ($meta_['fingerprint'] ?? null) === $fingerprint;
    }

    public function Write_Fingerprint(string $output_dir, string $fingerprint): void
    {
        $meta_path_ = rtrim($output_dir, '\\/').DIRECTORY_SEPARATOR.self::META_FILE;
        $payload_ = [
            'fingerprint' => $fingerprint,
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];

        $this->files_->put($meta_path_, json_encode($payload_, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL);
    }

    public function Is_Relevant_For_Context(string $relative_path): bool
    {
        $excluded_prefixes_ = [
            '.codex/',
            '.git/',
            'bootstrap/cache/',
            'node_modules/',
            'public/build/',
            'storage/framework/',
            'storage/logs/',
            'storage/pail/',
            'vendor/',
        ];

        $normalized_ = str_replace('\\', '/', $relative_path);

        foreach ($excluded_prefixes_ as $excluded_) {
            if (str_starts_with($normalized_, $excluded_)) {
                return false;
            }
        }

        return ! str_starts_with($normalized_, 'storage/app/ai-context/');
    }

    public function Resolve_Output_Dir(string $output_dir): string
    {
        $normalized_ = trim($output_dir);

        if ($normalized_ === '') {
            $normalized_ = self::DEFAULT_OUTPUT_DIR;
        }

        if ($this->Is_Absolute_Path($normalized_)) {
            return $normalized_;
        }

        return base_path($normalized_);
    }

    public function Relative_Path(SplFileInfo $file): string
    {
        $path_ = str_replace('\\', '/', $file->getPathname());
        $base_path_ = str_replace('\\', '/', base_path());

        return ltrim(str_replace($base_path_, '', $path_), '/');
    }

    public function Display_Path(string $path): string
    {
        $base_path_ = str_replace('\\', '/', base_path());
        $normalized_path_ = str_replace('\\', '/', $path);

        if (str_starts_with($normalized_path_, $base_path_.'/')) {
            return substr($normalized_path_, strlen($base_path_) + 1);
        }

        return $normalized_path_;
    }

    public function Is_Absolute_Path(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path) === 1;
    }

    /** @return array<int, SplFileInfo> */
    public function All_Files_Cached(): array
    {
        if ($this->all_files_cache_ !== null) {
            return $this->all_files_cache_;
        }

        $this->all_files_cache_ = $this->files_->allFiles(base_path());

        return $this->all_files_cache_;
    }

    /** @return array<int, SplFileInfo> */
    public function Relevant_Context_Files_Cached(): array
    {
        if ($this->relevant_context_files_cache_ !== null) {
            return $this->relevant_context_files_cache_;
        }

        $files_ = [];

        foreach ($this->All_Files_Cached() as $file_) {
            if ($this->Is_Relevant_For_Context($this->Relative_Path($file_))) {
                $files_[] = $file_;
            }
        }

        $this->relevant_context_files_cache_ = $files_;

        return $this->relevant_context_files_cache_;
    }
}
