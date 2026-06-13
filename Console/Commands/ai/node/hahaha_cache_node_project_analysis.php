<?php

namespace L_Lib\Console\Commands\ai\node;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use SplFileInfo;
use Throwable;

#[Signature('app:hahaha-cache-node-project-analysis
    {--output-dir=storage/app/ai-context/node : Directory used to store Node / Codex analysis cache files}
    {--with-tests : Include test file list in the analysis payload}
    {--force : Rebuild the cache even when the fingerprint is unchanged}', aliases: ['l_lib:app:hahaha-cache-node-project-analysis'])]
#[Description('為 Node / Codex 產生精簡且可快取的 Laravel 專案分析摘要')]
class hahaha_cache_node_project_analysis extends Command
{
    private const DEFAULT_OUTPUT_DIR = 'storage/app/ai-context/node';

    private const PROJECT_MARKDOWN_FILE = 'project-analysis.md';

    private const PROJECT_JSON_FILE = 'project-analysis.json';

    private const PAGE_NODE_MARKDOWN_FILE = 'page-node-analysis.md';

    private const PAGE_NODE_JSON_FILE = 'page-node-analysis.json';

    private const META_FILE = '.hahaha_cache_node_project_analysis.meta.json';

    private const EXCLUDED_PREFIXES = [
        '.codex/',
        '.git/',
        'bootstrap/cache/',
        'node_modules/',
        'public/build/',
        'public/hot/',
        'storage/app/ai-context/',
        'storage/framework/',
        'storage/logs/',
        'storage/pail/',
        'vendor/',
    ];

    private const STATIC_INCLUDED_PREFIXES = [
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

    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
        $this->base_path_normalized_ = str_replace('\\', '/', base_path());
    }

    public function handle(): int
    {
        $output_dir_ = $this->resolveOutputDir((string) $this->option('output-dir'));
        $this->files->ensureDirectoryExists($output_dir_);

        $classmap_roots_ = $this->classmapRootsResolve_();
        $scan_result_ = $this->collectRelevantFiles($output_dir_, $classmap_roots_);
        $fingerprint_ = $this->buildFingerprint($scan_result_['files']);

        if (! (bool) $this->option('force') && $this->isFingerprintUnchanged($output_dir_, $fingerprint_)) {
            $this->components->info(sprintf('程式碼未變更，略過重建：%s', $this->displayPath($output_dir_)));

            return self::SUCCESS;
        }

        $page_node_analysis_ = $this->pageNodeAnalysisResolve_($classmap_roots_);
        $project_analysis_ = $this->buildProjectAnalysis($scan_result_['files'], $classmap_roots_, $page_node_analysis_);

        $this->writeFile(
            $output_dir_.DIRECTORY_SEPARATOR.self::PROJECT_MARKDOWN_FILE,
            $this->renderProjectMarkdown($project_analysis_)
        );
        $this->writeFile(
            $output_dir_.DIRECTORY_SEPARATOR.self::PROJECT_JSON_FILE,
            json_encode($project_analysis_, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL
        );
        $this->writeFile(
            $output_dir_.DIRECTORY_SEPARATOR.self::PAGE_NODE_MARKDOWN_FILE,
            $this->renderPageNodeMarkdown($page_node_analysis_)
        );
        $this->writeFile(
            $output_dir_.DIRECTORY_SEPARATOR.self::PAGE_NODE_JSON_FILE,
            json_encode($page_node_analysis_, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL
        );
        $this->writeMeta($output_dir_, $fingerprint_, $project_analysis_, $page_node_analysis_);

        $this->components->info(sprintf('Node / Codex 專案分析快取已輸出：%s', $this->displayPath($output_dir_)));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{path: string, size: int, modified_at: string, extension: string}>  $files_
     * @param  array<int, string>  $classmap_roots_
     * @param  array{
     *     classmap_roots: array<int, string>,
     *     node_directory_count: int,
     *     node_directories: array<int, array{
     *         path: string,
     *         classmap_root: string,
     *         file_count: int,
     *         tree: array<int, string>,
     *         controllers: array<int, string>,
     *         views: array<int, string>,
     *         configs: array<int, string>,
     *         models: array<int, string>,
     *         tests: array<int, string>,
     *         others: array<int, string>
     *     }>
     * } $page_node_analysis_
     * @return array{
     *     generated_at: string,
     *     project: array<string, mixed>,
     *     summary: array<string, mixed>,
     *     directories: array<int, array{path: string, file_count: int}>,
     *     classmap: array{roots: array<int, string>, node_directory_count: int},
     *     page_nodes: array{
     *         classmap_roots: array<int, string>,
     *         node_directory_count: int,
     *         node_directories: array<int, array{
     *             path: string,
     *             classmap_root: string,
     *             file_count: int,
     *             tree: array<int, string>,
     *             controllers: array<int, string>,
     *             views: array<int, string>,
     *             configs: array<int, string>,
     *             models: array<int, string>,
     *             tests: array<int, string>,
     *             others: array<int, string>
     *         }>
     *     },
     *     routes: array<string, mixed>,
     *     database: array<string, mixed>,
     *     packages: array<string, mixed>,
     *     symbols: array<int, array{path: string, kind: string, name: string}>,
     *     recent_files: array<int, array{path: string, modified_at: string}>,
     *     tests: array<string, mixed>
     * }
     */
    private function buildProjectAnalysis(array $files_, array $classmap_roots_, array $page_node_analysis_): array
    {
        $generated_at_ = Carbon::now()->toDateTimeString();
        $routes_ = $this->routesSummaryResolve_();
        $database_ = $this->databaseSummaryResolve_();
        $packages_ = $this->packagesSummaryResolve_();
        $tests_ = $this->testsSummaryResolve_($files_);
        $symbols_ = $this->phpSymbolsResolve_($files_);

        return [
            'generated_at' => $generated_at_,
            'project' => [
                'root' => base_path(),
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'app_name' => (string) config('app.name'),
                'app_env' => (string) config('app.env'),
                'app_debug' => (bool) config('app.debug'),
                'app_url' => (string) config('app.url'),
                'database_default' => (string) config('database.default'),
                'cache_default' => (string) config('cache.default'),
                'queue_default' => (string) config('queue.default'),
            ],
            'summary' => [
                'relevant_file_count' => count($files_),
                'route_count' => $routes_['count'],
                'database_table_count' => $database_['table_count'],
                'php_symbol_count' => count($symbols_),
                'test_file_count' => $tests_['count'],
                'classmap_root_count' => count($classmap_roots_),
                'page_node_directory_count' => $page_node_analysis_['node_directory_count'],
            ],
            'directories' => $this->directoryBucketsResolve_($files_, $classmap_roots_),
            'classmap' => [
                'roots' => $classmap_roots_,
                'node_directory_count' => $page_node_analysis_['node_directory_count'],
            ],
            'page_nodes' => $page_node_analysis_,
            'routes' => $routes_,
            'database' => $database_,
            'packages' => $packages_,
            'symbols' => $symbols_,
            'recent_files' => $this->recentFilesResolve_($files_),
            'tests' => $tests_,
        ];
    }

    /**
     * @return array{count: int, items: array<int, array{methods: string, uri: string, name: string, action: string}>}
     */
    private function routesSummaryResolve_(): array
    {
        $items_ = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route_) => [
                'methods' => implode('|', array_values(array_filter($route_->methods(), static fn (string $method_): bool => $method_ !== 'HEAD'))),
                'uri' => $route_->uri(),
                'name' => $route_->getName() ?? '-',
                'action' => $route_->getActionName(),
            ])
            ->sortBy('uri')
            ->values()
            ->all();

        return [
            'count' => count($items_),
            'items' => $items_,
        ];
    }

    /**
     * @return array{status: string, database_name: string|null, table_count: int, tables: array<int, array{name: string, column_count: int, columns: array<int, string>}>, reason?: string}
     */
    private function databaseSummaryResolve_(): array
    {
        try {
            $tables_ = collect(Schema::getTables())
                ->unique(static fn (array $table_): string => (string) ($table_['name'] ?? $table_['schema_qualified_name'] ?? 'unknown'))
                ->values()
                ->all();
        } catch (Throwable $throwable_) {
            return [
                'status' => 'unavailable',
                'database_name' => null,
                'table_count' => 0,
                'tables' => [],
                'reason' => $throwable_->getMessage(),
            ];
        }

        $items_ = [];

        foreach ($tables_ as $table_) {
            $table_name_ = $table_['name'] ?? $table_['schema_qualified_name'] ?? 'unknown';
            $columns_ = collect(Schema::getColumns($table_name_))
                ->map(static fn (array $column_): string => sprintf('%s:%s', $column_['name'], $column_['type_name']))
                ->values()
                ->all();

            $items_[] = [
                'name' => $table_name_,
                'column_count' => count($columns_),
                'columns' => $columns_,
            ];
        }

        usort($items_, static fn (array $left_, array $right_): int => strcmp($left_['name'], $right_['name']));

        return [
            'status' => 'available',
            'database_name' => DB::getDatabaseName(),
            'table_count' => count($items_),
            'tables' => $items_,
        ];
    }

    /**
     * @return array{composer_require: array<int, string>, composer_require_dev: array<int, string>, npm_dependencies: array<int, string>, npm_dev_dependencies: array<int, string>, composer_classmap: array<int, string>}
     */
    private function packagesSummaryResolve_(): array
    {
        $composer_ = $this->jsonFileResolve_(base_path('composer.json'));
        $package_json_ = $this->jsonFileResolve_(base_path('package.json'));

        return [
            'composer_require' => array_values(array_keys($composer_['require'] ?? [])),
            'composer_require_dev' => array_values(array_keys($composer_['require-dev'] ?? [])),
            'npm_dependencies' => array_values(array_keys($package_json_['dependencies'] ?? [])),
            'npm_dev_dependencies' => array_values(array_keys($package_json_['devDependencies'] ?? [])),
            'composer_classmap' => array_values($composer_['autoload']['classmap'] ?? []),
        ];
    }

    /**
     * @param  array<int, array{path: string, size: int, modified_at: string, extension: string}>  $files_
     * @param  array<int, string>  $classmap_roots_
     * @return array<int, array{path: string, file_count: int}>
     */
    private function directoryBucketsResolve_(array $files_, array $classmap_roots_): array
    {
        $bucket_counts_ = [
            'app' => 0,
            'library/hahaha_laravel_lib' => 0,
            'config' => 0,
            'database' => 0,
            'resources' => 0,
            'routes' => 0,
            'tests' => 0,
            'other' => 0,
        ];

        foreach ($classmap_roots_ as $classmap_root_) {
            $bucket_counts_[$classmap_root_] = 0;
        }

        foreach ($files_ as $file_) {
            $path_ = $file_['path'];
            $bucket_ = null;

            foreach ($classmap_roots_ as $classmap_root_) {
                if (str_starts_with($path_, $classmap_root_.'/') || $path_ === $classmap_root_) {
                    $bucket_ = $classmap_root_;
                    break;
                }
            }

            if ($bucket_ === null) {
                $bucket_ = match (true) {
                    str_starts_with($path_, 'app/') => 'app',
                    str_starts_with($path_, 'library/hahaha_laravel_lib/') => 'library/hahaha_laravel_lib',
                    str_starts_with($path_, 'config/') => 'config',
                    str_starts_with($path_, 'database/') => 'database',
                    str_starts_with($path_, 'resources/') => 'resources',
                    str_starts_with($path_, 'routes/') => 'routes',
                    str_starts_with($path_, 'tests/') => 'tests',
                    default => 'other',
                };
            }

            $bucket_counts_[$bucket_] = ($bucket_counts_[$bucket_] ?? 0) + 1;
        }

        $items_ = [];

        foreach ($bucket_counts_ as $path_ => $file_count_) {
            $items_[] = [
                'path' => $path_,
                'file_count' => $file_count_,
            ];
        }

        return $items_;
    }

    /**
     * @param  array<int, array{path: string, size: int, modified_at: string, extension: string}>  $files_
     * @return array<int, array{path: string, kind: string, name: string}>
     */
    private function phpSymbolsResolve_(array $files_): array
    {
        $symbols_ = [];

        foreach ($files_ as $file_) {
            if ($file_['extension'] !== 'php') {
                continue;
            }

            $contents_ = $this->files->get(base_path($file_['path']));

            if (preg_match('/\b(interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)\b|\bclass\s+(?!extends\b)([A-Za-z_][A-Za-z0-9_]*)\b/m', $contents_, $matches_) !== 1) {
                continue;
            }

            $kind_ = $matches_[1] !== '' ? $matches_[1] : 'class';
            $name_ = $matches_[2] !== '' ? $matches_[2] : ($matches_[3] ?? '');

            if ($name_ === '') {
                continue;
            }

            $symbols_[] = [
                'path' => $file_['path'],
                'kind' => $kind_,
                'name' => $name_,
            ];
        }

        usort($symbols_, static fn (array $left_, array $right_): int => strcmp($left_['path'], $right_['path']));

        return $symbols_;
    }

    /**
     * @param  array<int, array{path: string, size: int, modified_at: string, extension: string}>  $files_
     * @return array<int, array{path: string, modified_at: string}>
     */
    private function recentFilesResolve_(array $files_): array
    {
        $recent_files_ = $files_;

        usort($recent_files_, static fn (array $left_, array $right_): int => strcmp($right_['modified_at'], $left_['modified_at']));

        return array_values(array_map(static fn (array $file_): array => [
            'path' => $file_['path'],
            'modified_at' => $file_['modified_at'],
        ], array_slice($recent_files_, 0, 25)));
    }

    /**
     * @param  array<int, array{path: string, size: int, modified_at: string, extension: string}>  $files_
     * @return array{count: int, items: array<int, string>}
     */
    private function testsSummaryResolve_(array $files_): array
    {
        $test_files_ = array_values(array_map(
            static fn (array $file_): string => $file_['path'],
            array_filter($files_, fn (array $file_): bool => str_starts_with($file_['path'], 'tests/')
                || $this->isNodeLikeFilePath_($file_['path']))
        ));

        sort($test_files_);

        if (! (bool) $this->option('with-tests')) {
            return [
                'count' => count($test_files_),
                'items' => [],
            ];
        }

        return [
            'count' => count($test_files_),
            'items' => $test_files_,
        ];
    }

    /**
     * @param  array<int, string>  $classmap_roots_
     * @return array{
     *     classmap_roots: array<int, string>,
     *     node_directory_count: int,
     *     node_directories: array<int, array{
     *         path: string,
     *         classmap_root: string,
     *         file_count: int,
     *         tree: array<int, string>,
     *         controllers: array<int, string>,
     *         views: array<int, string>,
     *         configs: array<int, string>,
     *         models: array<int, string>,
     *         tests: array<int, string>,
     *         others: array<int, string>
     *     }>
     * }
     */
    private function pageNodeAnalysisResolve_(array $classmap_roots_): array
    {
        $node_directories_ = [];

        foreach ($classmap_roots_ as $classmap_root_) {
            $absolute_root_path_ = base_path($classmap_root_);

            if (! $this->files->isDirectory($absolute_root_path_)) {
                continue;
            }

            foreach ($this->nodeDirectoriesFromRootResolve_($classmap_root_) as $node_directory_) {
                $file_paths_ = $this->nodeDirectoryFilesResolve_($node_directory_);
                $categorized_files_ = $this->nodeFilesCategorize_($file_paths_);

                $node_directories_[] = [
                    'path' => $node_directory_,
                    'classmap_root' => $classmap_root_,
                    'file_count' => count($file_paths_),
                    'tree' => $this->treeLinesResolve_($node_directory_, $file_paths_),
                    'controllers' => $categorized_files_['controllers'],
                    'views' => $categorized_files_['views'],
                    'configs' => $categorized_files_['configs'],
                    'models' => $categorized_files_['models'],
                    'tests' => $categorized_files_['tests'],
                    'others' => $categorized_files_['others'],
                ];
            }
        }

        usort($node_directories_, static fn (array $left_, array $right_): int => strcmp($left_['path'], $right_['path']));

        return [
            'classmap_roots' => $classmap_roots_,
            'node_directory_count' => count($node_directories_),
            'node_directories' => $node_directories_,
        ];
    }

    /**
     * @param  array{
     *     generated_at: string,
     *     project: array<string, mixed>,
     *     summary: array<string, mixed>,
     *     directories: array<int, array{path: string, file_count: int}>,
     *     classmap: array{roots: array<int, string>, node_directory_count: int},
     *     page_nodes: array{
     *         classmap_roots: array<int, string>,
     *         node_directory_count: int,
     *         node_directories: array<int, array{
     *             path: string,
     *             classmap_root: string,
     *             file_count: int,
     *             tree: array<int, string>,
     *             controllers: array<int, string>,
     *             views: array<int, string>,
     *             configs: array<int, string>,
     *             models: array<int, string>,
     *             tests: array<int, string>,
     *             others: array<int, string>
     *         }>
     *     },
     *     routes: array<string, mixed>,
     *     database: array<string, mixed>,
     *     packages: array<string, mixed>,
     *     symbols: array<int, array{path: string, kind: string, name: string}>,
     *     recent_files: array<int, array{path: string, modified_at: string}>,
     *     tests: array<string, mixed>
     * } $project_analysis_
     */
    private function renderProjectMarkdown(array $project_analysis_): string
    {
        $lines_ = [
            '# Node Project Analysis',
            '',
            'Generated at: '.$project_analysis_['generated_at'],
            'Root: '.$project_analysis_['project']['root'],
            '',
            '## Project',
            '',
            '- Laravel: '.$project_analysis_['project']['laravel_version'],
            '- PHP: '.$project_analysis_['project']['php_version'],
            '- App: '.$project_analysis_['project']['app_name'].' ['.$project_analysis_['project']['app_env'].']',
            '- Debug: '.($project_analysis_['project']['app_debug'] ? 'true' : 'false'),
            '- URL: '.$project_analysis_['project']['app_url'],
            '- Database default: '.$project_analysis_['project']['database_default'],
            '- Cache default: '.$project_analysis_['project']['cache_default'],
            '- Queue default: '.$project_analysis_['project']['queue_default'],
            '',
            '## Summary',
            '',
            '- Relevant files: '.$project_analysis_['summary']['relevant_file_count'],
            '- Routes: '.$project_analysis_['summary']['route_count'],
            '- Database tables: '.$project_analysis_['summary']['database_table_count'],
            '- PHP symbols: '.$project_analysis_['summary']['php_symbol_count'],
            '- Tests: '.$project_analysis_['summary']['test_file_count'],
            '- Classmap roots: '.$project_analysis_['summary']['classmap_root_count'],
            '- Page node directories: '.$project_analysis_['summary']['page_node_directory_count'],
            '',
            '## Classmap',
            '',
        ];

        foreach ($project_analysis_['classmap']['roots'] as $classmap_root_) {
            $lines_[] = '- '.$classmap_root_;
        }

        $lines_[] = '';
        $lines_[] = '## Directories';
        $lines_[] = '';

        foreach ($project_analysis_['directories'] as $directory_) {
            $lines_[] = sprintf('- %s: %d', $directory_['path'], $directory_['file_count']);
        }

        $lines_[] = '';
        $lines_[] = '## Page Nodes';
        $lines_[] = '';
        $lines_[] = '- Node directory count: '.$project_analysis_['page_nodes']['node_directory_count'];

        foreach ($project_analysis_['page_nodes']['node_directories'] as $node_directory_) {
            $lines_[] = sprintf(
                '- %s [classmap: %s] controllers=%d views=%d configs=%d models=%d tests=%d others=%d',
                $node_directory_['path'],
                $node_directory_['classmap_root'],
                count($node_directory_['controllers']),
                count($node_directory_['views']),
                count($node_directory_['configs']),
                count($node_directory_['models']),
                count($node_directory_['tests']),
                count($node_directory_['others'])
            );
        }

        $lines_[] = '';
        $lines_[] = '## Routes';
        $lines_[] = '';

        foreach ($project_analysis_['routes']['items'] as $route_) {
            $lines_[] = sprintf('- [%s] %s (%s) => %s', $route_['methods'], $route_['uri'], $route_['name'], $route_['action']);
        }

        $lines_[] = '';
        $lines_[] = '## Database';
        $lines_[] = '';
        $lines_[] = '- Status: '.$project_analysis_['database']['status'];
        $lines_[] = '- Database: '.($project_analysis_['database']['database_name'] ?? '-');
        $lines_[] = '- Tables: '.$project_analysis_['database']['table_count'];

        if (($project_analysis_['database']['reason'] ?? '') !== '') {
            $lines_[] = '- Reason: '.$project_analysis_['database']['reason'];
        }

        $lines_[] = '';

        foreach ($project_analysis_['database']['tables'] as $table_) {
            $lines_[] = sprintf('- %s (%d): %s', $table_['name'], $table_['column_count'], implode(', ', $table_['columns']));
        }

        $lines_[] = '';
        $lines_[] = '## Packages';
        $lines_[] = '';
        $lines_[] = '- composer require: '.implode(', ', $project_analysis_['packages']['composer_require']);
        $lines_[] = '- composer require-dev: '.implode(', ', $project_analysis_['packages']['composer_require_dev']);
        $lines_[] = '- composer classmap: '.implode(', ', $project_analysis_['packages']['composer_classmap']);
        $lines_[] = '- npm dependencies: '.implode(', ', $project_analysis_['packages']['npm_dependencies']);
        $lines_[] = '- npm devDependencies: '.implode(', ', $project_analysis_['packages']['npm_dev_dependencies']);
        $lines_[] = '';
        $lines_[] = '## PHP Symbols';
        $lines_[] = '';

        foreach ($project_analysis_['symbols'] as $symbol_) {
            $lines_[] = sprintf('- %s => %s %s', $symbol_['path'], $symbol_['kind'], $symbol_['name']);
        }

        $lines_[] = '';
        $lines_[] = '## Recent Files';
        $lines_[] = '';

        foreach ($project_analysis_['recent_files'] as $file_) {
            $lines_[] = sprintf('- %s (%s)', $file_['path'], $file_['modified_at']);
        }

        if ($project_analysis_['tests']['items'] !== []) {
            $lines_[] = '';
            $lines_[] = '## Tests';
            $lines_[] = '';

            foreach ($project_analysis_['tests']['items'] as $test_file_) {
                $lines_[] = '- '.$test_file_;
            }
        }

        $lines_[] = '';

        return implode(PHP_EOL, $lines_).PHP_EOL;
    }

    /**
     * @param  array{
     *     classmap_roots: array<int, string>,
     *     node_directory_count: int,
     *     node_directories: array<int, array{
     *         path: string,
     *         classmap_root: string,
     *         file_count: int,
     *         tree: array<int, string>,
     *         controllers: array<int, string>,
     *         views: array<int, string>,
     *         models: array<int, string>,
     *         others: array<int, string>
     *     }>
     * } $page_node_analysis_
     */
    private function renderPageNodeMarkdown(array $page_node_analysis_): string
    {
        $lines_ = [
            '# Page Node Analysis',
            '',
            'Generated at: '.Carbon::now()->toDateTimeString(),
            '- Classmap roots: '.implode(', ', $page_node_analysis_['classmap_roots']),
            '- Node directory count: '.$page_node_analysis_['node_directory_count'],
            '',
        ];

        foreach ($page_node_analysis_['node_directories'] as $node_directory_) {
            $lines_[] = '## '.$node_directory_['path'];
            $lines_[] = '';
            $lines_[] = '- Classmap root: '.$node_directory_['classmap_root'];
            $lines_[] = '- File count: '.$node_directory_['file_count'];
            $lines_[] = '- Controllers: '.count($node_directory_['controllers']);
            $lines_[] = '- Views: '.count($node_directory_['views']);
            $lines_[] = '- Configs: '.count($node_directory_['configs']);
            $lines_[] = '- Models: '.count($node_directory_['models']);
            $lines_[] = '- Tests: '.count($node_directory_['tests']);
            $lines_[] = '- Others: '.count($node_directory_['others']);
            $lines_[] = '';
            $lines_[] = '```text';

            foreach ($node_directory_['tree'] as $tree_line_) {
                $lines_[] = $tree_line_;
            }

            $lines_[] = '```';
            $lines_[] = '';
        }

        return implode(PHP_EOL, $lines_).PHP_EOL;
    }

    private function writeFile(string $path_, string $contents_): void
    {
        if ($this->files->exists($path_) && $this->files->get($path_) === $contents_) {
            return;
        }

        $this->files->put($path_, $contents_);
    }

    /**
     * @param  array<int, array{path: string, size: int, modified_at: string, extension: string}>  $files_
     */
    private function buildFingerprint(array $files_): string
    {
        $parts_ = array_map(static fn (array $file_): string => sprintf(
            '%s|%s|%d',
            $file_['path'],
            $file_['modified_at'],
            $file_['size']
        ), $files_);

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

    /**
     * @param  array{
     *     generated_at: string,
     *     project: array<string, mixed>,
     *     summary: array<string, mixed>,
     *     directories: array<int, array{path: string, file_count: int}>,
     *     classmap: array{roots: array<int, string>, node_directory_count: int},
     *     page_nodes: array{
     *         classmap_roots: array<int, string>,
     *         node_directory_count: int,
     *         node_directories: array<int, array{
     *             path: string,
     *             classmap_root: string,
     *             file_count: int,
     *             tree: array<int, string>,
     *             controllers: array<int, string>,
     *             views: array<int, string>,
     *             models: array<int, string>,
     *             others: array<int, string>
     *         }>
     *     },
     *     routes: array<string, mixed>,
     *     database: array<string, mixed>,
     *     packages: array<string, mixed>,
     *     symbols: array<int, array{path: string, kind: string, name: string}>,
     *     recent_files: array<int, array{path: string, modified_at: string}>,
     *     tests: array<string, mixed>
     * } $project_analysis_
     * @param  array{
     *     classmap_roots: array<int, string>,
     *     node_directory_count: int,
     *     node_directories: array<int, array{
     *         path: string,
     *         classmap_root: string,
     *         file_count: int,
     *         tree: array<int, string>,
     *         controllers: array<int, string>,
     *         views: array<int, string>,
     *         models: array<int, string>,
     *         others: array<int, string>
     *     }>
     * } $page_node_analysis_
     */
    private function writeMeta(string $output_dir_, string $fingerprint_, array $project_analysis_, array $page_node_analysis_): void
    {
        $meta_path_ = rtrim($output_dir_, '\\/').DIRECTORY_SEPARATOR.self::META_FILE;

        $this->files->put($meta_path_, json_encode([
            'fingerprint' => $fingerprint_,
            'updated_at' => $project_analysis_['generated_at'],
            'classmap_roots' => $page_node_analysis_['classmap_roots'],
            'node_directory_count' => $page_node_analysis_['node_directory_count'],
            'files' => [
                'project_markdown' => self::PROJECT_MARKDOWN_FILE,
                'project_json' => self::PROJECT_JSON_FILE,
                'page_node_markdown' => self::PAGE_NODE_MARKDOWN_FILE,
                'page_node_json' => self::PAGE_NODE_JSON_FILE,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL);
    }

    private function resolveOutputDir(string $output_dir_): string
    {
        $normalized_ = trim($output_dir_);

        if ($normalized_ === '') {
            $normalized_ = self::DEFAULT_OUTPUT_DIR;
        }

        return $this->isAbsolutePath($normalized_) ? $normalized_ : base_path($normalized_);
    }

    /**
     * @param  array<int, string>  $classmap_roots_
     * @return array{files: array<int, array{path: string, size: int, modified_at: string, extension: string}>}
     */
    private function collectRelevantFiles(string $output_dir_, array $classmap_roots_): array
    {
        $output_dir_normalized_ = str_replace('\\', '/', rtrim($output_dir_, '\\/'));
        $files_ = [];

        foreach ($this->files->allFiles(base_path()) as $file_) {
            $path_ = $this->relativePath($file_);

            if (! $this->isRelevantPath($path_, $classmap_roots_)) {
                continue;
            }

            $absolute_path_normalized_ = str_replace('\\', '/', $file_->getPathname());

            if (str_starts_with($absolute_path_normalized_, $output_dir_normalized_.'/')) {
                continue;
            }

            $files_[] = [
                'path' => $path_,
                'size' => $file_->getSize(),
                'modified_at' => Carbon::createFromTimestamp($file_->getMTime())->toDateTimeString(),
                'extension' => strtolower($file_->getExtension()),
            ];
        }

        usort($files_, static fn (array $left_, array $right_): int => strcmp($left_['path'], $right_['path']));

        return [
            'files' => $files_,
        ];
    }

    /**
     * @param  array<int, string>  $classmap_roots_
     */
    private function isRelevantPath(string $path_, array $classmap_roots_): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $excluded_prefix_) {
            if (str_starts_with($path_, $excluded_prefix_)) {
                return false;
            }
        }

        foreach (self::STATIC_INCLUDED_PREFIXES as $included_prefix_) {
            if (str_starts_with($path_, $included_prefix_)) {
                return true;
            }
        }

        foreach ($classmap_roots_ as $classmap_root_) {
            if (str_starts_with($path_, $classmap_root_.'/') || $path_ === $classmap_root_) {
                return true;
            }
        }

        return in_array($path_, ['artisan', 'composer.json', 'package.json', 'phpunit.xml', 'vite.config.js'], true);
    }

    private function relativePath(SplFileInfo $file_): string
    {
        return ltrim(str_replace($this->base_path_normalized_, '', str_replace('\\', '/', $file_->getPathname())), '/');
    }

    private function displayPath(string $path_): string
    {
        $normalized_path_ = str_replace('\\', '/', $path_);

        if (str_starts_with($normalized_path_, $this->base_path_normalized_.'/')) {
            return substr($normalized_path_, strlen($this->base_path_normalized_) + 1);
        }

        return $normalized_path_;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFileResolve_(string $path_): array
    {
        if (! $this->files->exists($path_)) {
            return [];
        }

        $decoded_ = json_decode($this->files->get($path_), true);

        return is_array($decoded_) ? $decoded_ : [];
    }

    /**
     * @return array<int, string>
     */
    private function classmapRootsResolve_(): array
    {
        $composer_ = $this->jsonFileResolve_(base_path('composer.json'));
        $classmap_roots_ = [];

        foreach ($composer_['autoload']['classmap'] ?? [] as $classmap_root_) {
            $normalized_root_ = trim(str_replace('\\', '/', (string) $classmap_root_), '/');

            if ($normalized_root_ === '') {
                continue;
            }

            $classmap_roots_[] = $normalized_root_;
        }

        $classmap_roots_ = array_values(array_unique($classmap_roots_));
        sort($classmap_roots_);

        return $classmap_roots_;
    }

    /**
     * @return array<int, string>
     */
    private function nodeDirectoriesFromRootResolve_(string $classmap_root_): array
    {
        $absolute_root_path_ = base_path($classmap_root_);
        $node_directories_ = [];

        $candidate_directories_ = [$absolute_root_path_, ...$this->files->allDirectories($absolute_root_path_)];

        foreach ($candidate_directories_ as $directory_path_) {
            $relative_directory_path_ = $this->relativePathFromAbsolutePath_($directory_path_);

            if (! $this->isNodeLikeDirectory_($relative_directory_path_)) {
                continue;
            }

            $node_directories_[] = $relative_directory_path_;
        }

        $node_directories_ = array_values(array_unique($node_directories_));
        $node_directories_ = array_values(array_filter($node_directories_, static function (string $directory_path_) use ($node_directories_): bool {
            foreach ($node_directories_ as $candidate_path_) {
                if ($candidate_path_ === $directory_path_) {
                    continue;
                }

                if (str_starts_with($directory_path_, $candidate_path_.'/')) {
                    return false;
                }
            }

            return true;
        }));
        sort($node_directories_);

        return $node_directories_;
    }

    /**
     * @return array<int, string>
     */
    private function nodeDirectoryFilesResolve_(string $node_directory_): array
    {
        $absolute_node_directory_ = base_path($node_directory_);
        $file_paths_ = [];

        if (! $this->files->isDirectory($absolute_node_directory_)) {
            return [];
        }

        foreach ($this->files->allFiles($absolute_node_directory_) as $file_) {
            $file_paths_[] = $this->relativePath($file_);
        }

        sort($file_paths_);

        return $file_paths_;
    }

    /**
     * @param  array<int, string>  $file_paths_
     * @return array{
     *     controllers: array<int, string>,
     *     views: array<int, string>,
     *     configs: array<int, string>,
     *     models: array<int, string>,
     *     tests: array<int, string>,
     *     others: array<int, string>
     * }
     */
    private function nodeFilesCategorize_(array $file_paths_): array
    {
        $categorized_files_ = [
            'controllers' => [],
            'views' => [],
            'configs' => [],
            'models' => [],
            'tests' => [],
            'others' => [],
        ];

        foreach ($file_paths_ as $file_path_) {
            $basename_ = basename($file_path_);

            if (preg_match('/^hahaha_controller_.+\.php$/', $basename_) === 1) {
                $categorized_files_['controllers'][] = $file_path_;
                continue;
            }

            if (preg_match('/^hahaha_view_.+\.blade\.php$/', $basename_) === 1) {
                $categorized_files_['views'][] = $file_path_;
                continue;
            }

            if (preg_match('/^hahaha_config_.+\.php$/', $basename_) === 1) {
                $categorized_files_['configs'][] = $file_path_;
                continue;
            }

            if (preg_match('/^hahaha_model_.+\.php$/', $basename_) === 1) {
                $categorized_files_['models'][] = $file_path_;
                continue;
            }

            if (preg_match('/^hahaha_test_.+\.php$/', $basename_) === 1) {
                $categorized_files_['tests'][] = $file_path_;
                continue;
            }

            $categorized_files_['others'][] = $file_path_;
        }

        return $categorized_files_;
    }

    /**
     * @param  array<int, string>  $file_paths_
     * @return array<int, string>
     */
    private function treeLinesResolve_(string $node_directory_, array $file_paths_): array
    {
        $relative_entries_ = [];

        foreach ($file_paths_ as $file_path_) {
            $relative_file_path_ = ltrim(substr($file_path_, strlen($node_directory_)), '/');

            if ($relative_file_path_ === '') {
                continue;
            }

            $segments_ = explode('/', $relative_file_path_);
            $partial_path_ = '';

            foreach ($segments_ as $index_ => $segment_) {
                $partial_path_ = $partial_path_ === '' ? $segment_ : $partial_path_.'/'.$segment_;
                $relative_entries_[$partial_path_] = $index_ < count($segments_) - 1 ? 'dir' : 'file';
            }
        }

        $lines_ = [basename($node_directory_)];
        $this->treeLinesAppend_($lines_, $relative_entries_, '', 0);

        return $lines_;
    }

    /**
     * @param  array<int, string>  $lines_
     * @param  array<string, string>  $relative_entries_
     */
    private function treeLinesAppend_(array &$lines_, array $relative_entries_, string $parent_path_, int $depth_): void
    {
        $children_ = [];

        foreach ($relative_entries_ as $entry_path_ => $entry_type_) {
            $normalized_parent_ = $parent_path_ === '' ? '' : $parent_path_.'/';

            if (! str_starts_with($entry_path_, $normalized_parent_)) {
                continue;
            }

            $remaining_path_ = substr($entry_path_, strlen($normalized_parent_));

            if ($remaining_path_ === '' || str_contains($remaining_path_, '/')) {
                continue;
            }

            $children_[$entry_path_] = $entry_type_;
        }

        ksort($children_);

        foreach ($children_ as $child_path_ => $child_type_) {
            $indent_ = str_repeat('  ', $depth_ + 1);
            $lines_[] = $indent_.'|-- '.basename($child_path_);

            if ($child_type_ === 'dir') {
                $this->treeLinesAppend_($lines_, $relative_entries_, $child_path_, $depth_ + 1);
            }
        }
    }

    private function relativePathFromAbsolutePath_(string $absolute_path_): string
    {
        return ltrim(str_replace($this->base_path_normalized_, '', str_replace('\\', '/', $absolute_path_)), '/');
    }

    private function isNodeLikeDirectory_(string $relative_directory_path_): bool
    {
        $absolute_directory_path_ = base_path($relative_directory_path_);
        $allowed_role_directories_ = ['controller', 'config', 'view', 'test', 'model'];
        $normalized_directory_path_ = str_replace('\\', '/', $relative_directory_path_);

        if (! $this->files->isDirectory($absolute_directory_path_)) {
            return false;
        }

        if (! str_starts_with($normalized_directory_path_, 'code/page/')
            && ! str_starts_with($normalized_directory_path_, 'code/template/')
            && ! str_starts_with($normalized_directory_path_, 'template/page/node/')
            && ! str_starts_with($normalized_directory_path_, 'code/test/node_fixture/')) {
            return false;
        }

        if (basename($absolute_directory_path_) === 'node') {
            foreach ($this->files->allFiles($absolute_directory_path_) as $file_) {
                if ($this->isNodeLikeFilePath_($this->relativePath($file_))) {
                    return true;
                }
            }

            return false;
        }

        foreach ($this->files->files($absolute_directory_path_) as $file_) {
            if ($this->isNodeLikeFilePath_($this->relativePath($file_))) {
                return true;
            }
        }

        $direct_child_directories_ = $this->files->directories($absolute_directory_path_);
        $contains_allowed_role_directory_ = false;

        foreach ($direct_child_directories_ as $directory_path_) {
            $directory_name_ = basename($directory_path_);

            if (! in_array($directory_name_, $allowed_role_directories_, true)) {
                return false;
            }

            $contains_allowed_role_directory_ = true;
        }

        if (! $contains_allowed_role_directory_) {
            return false;
        }

        foreach ($direct_child_directories_ as $directory_path_) {
            foreach ($this->files->allFiles($directory_path_) as $file_) {
                if ($this->isNodeLikeFilePath_($this->relativePath($file_))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isNodeLikeFilePath_(string $file_path_): bool
    {
        $basename_ = basename($file_path_);

        return preg_match('/^hahaha_(controller|view|config|model|test)_.+\.(?:php|blade\.php)$/', $basename_) === 1;
    }

    private function isAbsolutePath(string $path_): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path_) === 1;
    }
}
