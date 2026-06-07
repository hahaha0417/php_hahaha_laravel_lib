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

#[Signature('l_lib:app:hahaha-cache-ai-context
    {--output-dir=storage/app/ai-context : Directory used to store AI context cache files}
    {--with-tests : Generate tests.md only when it is explicitly needed}')]
#[Description('為 AI 助手快取多份專案上下文檔案')]
class hahaha_cache_ai_context extends Command
{
    private const DEFAULT_OUTPUT_DIR = 'storage/app/ai-context';
    private const META_FILE = '.hahaha_cache_meta.json';

    /** @var array<int, SplFileInfo>|null */
    private ?array $all_files_cache_ = null;

    /** @var array<int, SplFileInfo>|null */
    private ?array $relevant_context_files_cache_ = null;

    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $output_dir_ = $this->resolveOutputDir((string) $this->option('output-dir'));
        $this->files->ensureDirectoryExists($output_dir_);

        $fingerprint_ = $this->buildFingerprint($output_dir_);

        if ($this->isFingerprintUnchanged($output_dir_, $fingerprint_)) {
            $this->components->info(sprintf('程式碼未變更，略過重建：%s', $this->displayPath($output_dir_)));

            return self::SUCCESS;
        }

        $this->writeFile($output_dir_, 'routes.md', $this->renderRoutesSummary());
        $this->writeFile($output_dir_, 'database-schema.md', $this->renderDatabaseSchemaSummary());
        $this->writeFile($output_dir_, 'config.md', $this->renderConfigSummary());
        $this->writeFile($output_dir_, 'packages.md', $this->renderPackageSummary());
        $this->testsSummaryWrite_($output_dir_);
        $this->writeFile($output_dir_, 'recent-changes.md', $this->renderRecentChangesSummary());
        $this->writeFile($output_dir_, 'ownership-map.md', $this->renderOwnershipMap());
        $this->writeFile($output_dir_, 'php-symbols.md', $this->renderPhpSymbolsSummary());
        $this->writeFingerprint($output_dir_, $fingerprint_);

        $this->components->info(sprintf('AI 上下文快取已輸出：%s', $this->displayPath($output_dir_)));

        return self::SUCCESS;
    }

    private function renderRoutesSummary(): string
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

    private function renderDatabaseSchemaSummary(): string
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

    private function renderConfigSummary(): string
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

    private function renderPackageSummary(): string
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

    private function renderTestSummary(): string
    {
        $test_files_ = collect($this->allFilesCached())
            ->filter(static fn (SplFileInfo $file_): bool => $file_->getExtension() === 'php')
            ->filter(fn (SplFileInfo $file_): bool => str_starts_with($this->relativePath($file_), 'tests/'))
            ->map(fn (SplFileInfo $file_): string => $this->relativePath($file_))
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

    private function testsSummaryWrite_(string $output_dir_): void
    {
        $tests_summary_path_ = $output_dir_.DIRECTORY_SEPARATOR.'tests.md';

        if (! (bool) $this->option('with-tests')) {
            if ($this->files->exists($tests_summary_path_)) {
                $this->files->delete($tests_summary_path_);
            }

            return;
        }

        $this->writeFile($output_dir_, 'tests.md', $this->renderTestSummary());
    }

    private function renderRecentChangesSummary(): string
    {
        $files_ = collect($this->relevantContextFilesCached())
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
            $lines_[] = sprintf('- %s (%s)', $this->relativePath($file_), Carbon::createFromTimestamp($file_->getMTime())->toDateTimeString());
        }

        return implode(PHP_EOL, $lines_).PHP_EOL;
    }

    private function renderOwnershipMap(): string
    {
        $files_ = collect($this->relevantContextFilesCached())
            ->map(fn (SplFileInfo $file_): string => $this->relativePath($file_))
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

    private function renderPhpSymbolsSummary(): string
    {
        $php_files_ = collect($this->relevantContextFilesCached())
            ->filter(fn (SplFileInfo $file_): bool => str_ends_with($this->relativePath($file_), '.php'))
            ->sortBy(fn (SplFileInfo $file_): string => $this->relativePath($file_))
            ->values();

        $lines_ = [
            '# PHP Symbols',
            '',
            'Generated at: '.Carbon::now()->toDateTimeString(),
            '',
        ];

        foreach ($php_files_ as $file_) {
            $contents_ = $this->files->get($file_->getPathname());

            if (preg_match('/\b(class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents_, $match_) !== 1) {
                continue;
            }

            $lines_[] = sprintf('- %s => %s %s', $this->relativePath($file_), $match_[1], $match_[2]);
        }

        return implode(PHP_EOL, $lines_).PHP_EOL;
    }

    private function writeFile(string $output_dir_, string $filename_, string $contents_): void
    {
        $path_ = $output_dir_.DIRECTORY_SEPARATOR.$filename_;

        if ($this->files->exists($path_) && $this->files->get($path_) === $contents_) {
            return;
        }

        $this->files->put($path_, $contents_);
    }

    private function buildFingerprint(string $output_dir_): string
    {
        $parts_ = [];
        $output_dir_normalized_ = str_replace('\\', '/', rtrim($output_dir_, '\\/'));

        foreach ($this->relevantContextFilesCached() as $file_) {
            $relative_path_ = $this->relativePath($file_);

            $full_path_normalized_ = str_replace('\\', '/', $file_->getPathname());
            if (str_starts_with($full_path_normalized_, $output_dir_normalized_.'/')) {
                continue;
            }

            $parts_[] = sprintf('%s|%d|%d', $relative_path_, $file_->getMTime(), $file_->getSize());
        }

        sort($parts_);

        return hash('sha256', implode("\n", $parts_));
    }

    private function isFingerprintUnchanged(string $output_dir_, string $fingerprint_): bool
    {
        $meta_path_ = rtrim($output_dir_, '\\/').DIRECTORY_SEPARATOR.self::META_FILE;

        if (! $this->files->exists($meta_path_)) {
            return false;
        }

        $meta_ = json_decode($this->files->get($meta_path_), true);

        return is_array($meta_) && ($meta_['fingerprint'] ?? null) === $fingerprint_;
    }

    private function writeFingerprint(string $output_dir_, string $fingerprint_): void
    {
        $meta_path_ = rtrim($output_dir_, '\\/').DIRECTORY_SEPARATOR.self::META_FILE;
        $payload_ = [
            'fingerprint' => $fingerprint_,
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];

        $this->files->put($meta_path_, json_encode($payload_, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL);
    }

    private function isRelevantForContext(string $relative_path_): bool
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

        $normalized_ = str_replace('\\', '/', $relative_path_);

        foreach ($excluded_prefixes_ as $excluded_) {
            if (str_starts_with($normalized_, $excluded_)) {
                return false;
            }
        }

        return ! str_starts_with($normalized_, 'storage/app/ai-context/');
    }

    private function resolveOutputDir(string $output_dir_): string
    {
        $normalized_ = trim($output_dir_);

        if ($normalized_ === '') {
            $normalized_ = self::DEFAULT_OUTPUT_DIR;
        }

        if ($this->isAbsolutePath($normalized_)) {
            return $normalized_;
        }

        return base_path($normalized_);
    }

    private function relativePath(SplFileInfo $file_): string
    {
        $path_ = str_replace('\\', '/', $file_->getPathname());
        $base_path_ = str_replace('\\', '/', base_path());

        return ltrim(str_replace($base_path_, '', $path_), '/');
    }

    private function displayPath(string $path_): string
    {
        $base_path_ = str_replace('\\', '/', base_path());
        $normalized_path_ = str_replace('\\', '/', $path_);

        if (str_starts_with($normalized_path_, $base_path_.'/')) {
            return substr($normalized_path_, strlen($base_path_) + 1);
        }

        return $normalized_path_;
    }

    private function isAbsolutePath(string $path_): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path_) === 1;
    }

    /** @return array<int, SplFileInfo> */
    private function allFilesCached(): array
    {
        if ($this->all_files_cache_ !== null) {
            return $this->all_files_cache_;
        }

        $this->all_files_cache_ = $this->files->allFiles(base_path());

        return $this->all_files_cache_;
    }

    /** @return array<int, SplFileInfo> */
    private function relevantContextFilesCached(): array
    {
        if ($this->relevant_context_files_cache_ !== null) {
            return $this->relevant_context_files_cache_;
        }

        $files_ = [];

        foreach ($this->allFilesCached() as $file_) {
            if ($this->isRelevantForContext($this->relativePath($file_))) {
                $files_[] = $file_;
            }
        }

        $this->relevant_context_files_cache_ = $files_;

        return $this->relevant_context_files_cache_;
    }
}
