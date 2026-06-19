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
    public const DEFAULT_OUTPUT_DIR = 'storage/app/ai-context/node';

    public const PROJECT_MARKDOWN_FILE = 'project-analysis.md';

    public const PROJECT_JSON_FILE = 'project-analysis.json';

    public const PAGE_NODE_MARKDOWN_FILE = 'page-node-analysis.md';

    public const PAGE_NODE_JSON_FILE = 'page-node-analysis.json';

    public const META_FILE = '.hahaha_cache_node_project_analysis.meta.json';

    public const EXCLUDED_PREFIXES = [
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

    public const STATIC_INCLUDED_PREFIXES = [
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

    public function __construct(
        public readonly Filesystem $files_,
    ) {
        parent::__construct();
        $this->base_path_normalized_ = str_replace('\\', '/', base_path());
    }

    public function handle(): int
    {
        $output_dir_ = $this->Resolve_Output_Dir((string) $this->option('output-dir'));
        $this->files_->ensureDirectoryExists($output_dir_);

        $classmap_roots_ = $this->Classmap_Roots_Resolve();
        $scan_result_ = $this->Collect_Relevant_Files($output_dir_, $classmap_roots_);
        $fingerprint_ = $this->Build_Fingerprint($scan_result_['files']);

        if (! (bool) $this->option('force') && $this->Is_Fingerprint_Unchanged($output_dir_, $fingerprint_)) {
            $this->components->info(sprintf('程式碼未變更，略過重建：%s', $this->Display_Path($output_dir_)));

            return self::SUCCESS;
        }

        $page_node_analysis_ = $this->Page_Node_Analysis_Resolve($classmap_roots_);
        $project_analysis_ = $this->Build_Project_Analysis($scan_result_['files'], $classmap_roots_, $page_node_analysis_);

        $this->Write_File(
            $output_dir_.DIRECTORY_SEPARATOR.self::PROJECT_MARKDOWN_FILE,
            $this->Render_Project_Markdown($project_analysis_)
        );
        $this->Write_File(
            $output_dir_.DIRECTORY_SEPARATOR.self::PROJECT_JSON_FILE,
            json_encode($project_analysis_, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL
        );
        $this->Write_File(
            $output_dir_.DIRECTORY_SEPARATOR.self::PAGE_NODE_MARKDOWN_FILE,
            $this->Render_Page_Node_Markdown($page_node_analysis_)
        );
        $this->Write_File(
            $output_dir_.DIRECTORY_SEPARATOR.self::PAGE_NODE_JSON_FILE,
            json_encode($page_node_analysis_, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL
        );
        $this->Write_Meta($output_dir_, $fingerprint_, $project_analysis_, $page_node_analysis_);

        $this->components->info(sprintf('Node / Codex 專案分析快取已輸出：%s', $this->Display_Path($output_dir_)));

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
    public function Build_Project_Analysis(array $files, array $classmap_roots, array $page_node_analysis): array
    {
        $generated_at_ = Carbon::now()->toDateTimeString();
        $routes_ = $this->Routes_Summary_Resolve();
        $database_ = $this->Database_Summary_Resolve();
        $packages_ = $this->Packages_Summary_Resolve();
        $tests_ = $this->Tests_Summary_Resolve($files);
        $symbols_ = $this->Php_Symbols_Resolve($files);

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
                'relevant_file_count' => count($files),
                'route_count' => $routes_['count'],
                'database_table_count' => $database_['table_count'],
                'php_symbol_count' => count($symbols_),
                'test_file_count' => $tests_['count'],
                'classmap_root_count' => count($classmap_roots),
                'page_node_directory_count' => $page_node_analysis['node_directory_count'],
            ],
            'directories' => $this->Directory_Buckets_Resolve($files, $classmap_roots),
            'classmap' => [
                'roots' => $classmap_roots,
                'node_directory_count' => $page_node_analysis['node_directory_count'],
            ],
            'page_nodes' => $page_node_analysis,
            'routes' => $routes_,
            'database' => $database_,
            'packages' => $packages_,
            'symbols' => $symbols_,
            'recent_files' => $this->Recent_Files_Resolve($files),
            'tests' => $tests_,
        ];
    }

    /**
     * @return array{count: int, items: array<int, array{methods: string, uri: string, name: string, action: string}>}
     */
    public function Routes_Summary_Resolve(): array
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
    public function Database_Summary_Resolve(): array
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
    public function Packages_Summary_Resolve(): array
    {
        $composer_ = $this->Json_File_Resolve(base_path('composer.json'));
        $package_json_ = $this->Json_File_Resolve(base_path('package.json'));

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
    public function Directory_Buckets_Resolve(array $files, array $classmap_roots): array
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

        foreach ($classmap_roots as $classmap_root_) {
            $bucket_counts_[$classmap_root_] = 0;
        }

        foreach ($files as $file_) {
            $path_ = $file_['path'];
            $bucket_ = null;

            foreach ($classmap_roots as $classmap_root_) {
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
    public function Php_Symbols_Resolve(array $files): array
    {
        $symbols_ = [];

        foreach ($files as $file_) {
            if ($file_['extension'] !== 'php') {
                continue;
            }

            $contents_ = $this->files_->get(base_path($file_['path']));

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
    public function Recent_Files_Resolve(array $files): array
    {
        $recent_files_ = $files;

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
    public function Tests_Summary_Resolve(array $files): array
    {
        $test_files_ = array_values(array_map(
            static fn (array $file_): string => $file_['path'],
            array_filter($files, fn (array $file_): bool => str_starts_with($file_['path'], 'tests/')
                || $this->Is_Node_Like_File_Path($file_['path']))
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
    public function Page_Node_Analysis_Resolve(array $classmap_roots): array
    {
        $node_directories_ = [];

        foreach ($classmap_roots as $classmap_root_) {
            $absolute_root_path_ = base_path($classmap_root_);

            if (! $this->files_->isDirectory($absolute_root_path_)) {
                continue;
            }

            foreach ($this->Node_Directories_From_Root_Resolve($classmap_root_) as $node_directory_) {
                $file_paths_ = $this->Node_Directory_Files_Resolve($node_directory_);
                $categorized_files_ = $this->Node_Files_Categorize($file_paths_);

                $node_directories_[] = [
                    'path' => $node_directory_,
                    'classmap_root' => $classmap_root_,
                    'file_count' => count($file_paths_),
                    'tree' => $this->Tree_Lines_Resolve($node_directory_, $file_paths_),
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
            'classmap_roots' => $classmap_roots,
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
    public function Render_Project_Markdown(array $project_analysis): string
    {
        $lines_ = [
            '# Node Project Analysis',
            '',
            'Generated at: '.$project_analysis['generated_at'],
            'Root: '.$project_analysis['project']['root'],
            '',
            '## Project',
            '',
            '- Laravel: '.$project_analysis['project']['laravel_version'],
            '- PHP: '.$project_analysis['project']['php_version'],
            '- App: '.$project_analysis['project']['app_name'].' ['.$project_analysis['project']['app_env'].']',
            '- Debug: '.($project_analysis['project']['app_debug'] ? 'true' : 'false'),
            '- URL: '.$project_analysis['project']['app_url'],
            '- Database default: '.$project_analysis['project']['database_default'],
            '- Cache default: '.$project_analysis['project']['cache_default'],
            '- Queue default: '.$project_analysis['project']['queue_default'],
            '',
            '## Summary',
            '',
            '- Relevant files: '.$project_analysis['summary']['relevant_file_count'],
            '- Routes: '.$project_analysis['summary']['route_count'],
            '- Database tables: '.$project_analysis['summary']['database_table_count'],
            '- PHP symbols: '.$project_analysis['summary']['php_symbol_count'],
            '- Tests: '.$project_analysis['summary']['test_file_count'],
            '- Classmap roots: '.$project_analysis['summary']['classmap_root_count'],
            '- Page node directories: '.$project_analysis['summary']['page_node_directory_count'],
            '',
            '## Classmap',
            '',
        ];

        foreach ($project_analysis['classmap']['roots'] as $classmap_root_) {
            $lines_[] = '- '.$classmap_root_;
        }

        $lines_[] = '';
        $lines_[] = '## Directories';
        $lines_[] = '';

        foreach ($project_analysis['directories'] as $directory_) {
            $lines_[] = sprintf('- %s: %d', $directory_['path'], $directory_['file_count']);
        }

        $lines_[] = '';
        $lines_[] = '## Page Nodes';
        $lines_[] = '';
        $lines_[] = '- Node directory count: '.$project_analysis['page_nodes']['node_directory_count'];

        foreach ($project_analysis['page_nodes']['node_directories'] as $node_directory_) {
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

        foreach ($project_analysis['routes']['items'] as $route_) {
            $lines_[] = sprintf('- [%s] %s (%s) => %s', $route_['methods'], $route_['uri'], $route_['name'], $route_['action']);
        }

        $lines_[] = '';
        $lines_[] = '## Database';
        $lines_[] = '';
        $lines_[] = '- Status: '.$project_analysis['database']['status'];
        $lines_[] = '- Database: '.($project_analysis['database']['database_name'] ?? '-');
        $lines_[] = '- Tables: '.$project_analysis['database']['table_count'];

        if (($project_analysis['database']['reason'] ?? '') !== '') {
            $lines_[] = '- Reason: '.$project_analysis['database']['reason'];
        }

        $lines_[] = '';

        foreach ($project_analysis['database']['tables'] as $table_) {
            $lines_[] = sprintf('- %s (%d): %s', $table_['name'], $table_['column_count'], implode(', ', $table_['columns']));
        }

        $lines_[] = '';
        $lines_[] = '## Packages';
        $lines_[] = '';
        $lines_[] = '- composer require: '.implode(', ', $project_analysis['packages']['composer_require']);
        $lines_[] = '- composer require-dev: '.implode(', ', $project_analysis['packages']['composer_require_dev']);
        $lines_[] = '- composer classmap: '.implode(', ', $project_analysis['packages']['composer_classmap']);
        $lines_[] = '- npm dependencies: '.implode(', ', $project_analysis['packages']['npm_dependencies']);
        $lines_[] = '- npm devDependencies: '.implode(', ', $project_analysis['packages']['npm_dev_dependencies']);
        $lines_[] = '';
        $lines_[] = '## PHP Symbols';
        $lines_[] = '';

        foreach ($project_analysis['symbols'] as $symbol_) {
            $lines_[] = sprintf('- %s => %s %s', $symbol_['path'], $symbol_['kind'], $symbol_['name']);
        }

        $lines_[] = '';
        $lines_[] = '## Recent Files';
        $lines_[] = '';

        foreach ($project_analysis['recent_files'] as $file_) {
            $lines_[] = sprintf('- %s (%s)', $file_['path'], $file_['modified_at']);
        }

        if ($project_analysis['tests']['items'] !== []) {
            $lines_[] = '';
            $lines_[] = '## Tests';
            $lines_[] = '';

            foreach ($project_analysis['tests']['items'] as $test_file_) {
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
    public function Render_Page_Node_Markdown(array $page_node_analysis): string
    {
        $lines_ = [
            '# Page Node Analysis',
            '',
            'Generated at: '.Carbon::now()->toDateTimeString(),
            '- Classmap roots: '.implode(', ', $page_node_analysis['classmap_roots']),
            '- Node directory count: '.$page_node_analysis['node_directory_count'],
            '',
        ];

        foreach ($page_node_analysis['node_directories'] as $node_directory_) {
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

    public function Write_File(string $path, string $contents): void
    {
        if ($this->files_->exists($path) && $this->files_->get($path) === $contents) {
            return;
        }

        $this->files_->put($path, $contents);
    }

    /**
     * @param  array<int, array{path: string, size: int, modified_at: string, extension: string}>  $files_
     */
    public function Build_Fingerprint(array $files): string
    {
        $parts_ = array_map(static fn (array $file_): string => sprintf(
            '%s|%s|%d',
            $file_['path'],
            $file_['modified_at'],
            $file_['size']
        ), $files);

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
    public function Write_Meta(string $output_dir, string $fingerprint, array $project_analysis, array $page_node_analysis): void
    {
        $meta_path_ = rtrim($output_dir, '\\/').DIRECTORY_SEPARATOR.self::META_FILE;

        $this->files_->put($meta_path_, json_encode([
            'fingerprint' => $fingerprint,
            'updated_at' => $project_analysis['generated_at'],
            'classmap_roots' => $page_node_analysis['classmap_roots'],
            'node_directory_count' => $page_node_analysis['node_directory_count'],
            'files' => [
                'project_markdown' => self::PROJECT_MARKDOWN_FILE,
                'project_json' => self::PROJECT_JSON_FILE,
                'page_node_markdown' => self::PAGE_NODE_MARKDOWN_FILE,
                'page_node_json' => self::PAGE_NODE_JSON_FILE,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL);
    }

    public function Resolve_Output_Dir(string $output_dir): string
    {
        $normalized_ = trim($output_dir);

        if ($normalized_ === '') {
            $normalized_ = self::DEFAULT_OUTPUT_DIR;
        }

        return $this->Is_Absolute_Path($normalized_) ? $normalized_ : base_path($normalized_);
    }

    /**
     * @param  array<int, string>  $classmap_roots_
     * @return array{files: array<int, array{path: string, size: int, modified_at: string, extension: string}>}
     */
    public function Collect_Relevant_Files(string $output_dir, array $classmap_roots): array
    {
        $output_dir_normalized_ = str_replace('\\', '/', rtrim($output_dir, '\\/'));
        $files_ = [];

        foreach ($this->files_->allFiles(base_path()) as $file_) {
            $path_ = $this->Relative_Path($file_);

            if (! $this->Is_Relevant_Path($path_, $classmap_roots)) {
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
    public function Is_Relevant_Path(string $path, array $classmap_roots): bool
    {
        foreach (self::EXCLUDED_PREFIXES as $excluded_prefix_) {
            if (str_starts_with($path, $excluded_prefix_)) {
                return false;
            }
        }

        foreach (self::STATIC_INCLUDED_PREFIXES as $included_prefix_) {
            if (str_starts_with($path, $included_prefix_)) {
                return true;
            }
        }

        foreach ($classmap_roots as $classmap_root_) {
            if (str_starts_with($path, $classmap_root_.'/') || $path === $classmap_root_) {
                return true;
            }
        }

        return in_array($path, ['artisan', 'composer.json', 'package.json', 'phpunit.xml', 'vite.config.js'], true);
    }

    public function Relative_Path(SplFileInfo $file): string
    {
        return ltrim(str_replace($this->base_path_normalized_, '', str_replace('\\', '/', $file->getPathname())), '/');
    }

    public function Display_Path(string $path): string
    {
        $normalized_path_ = str_replace('\\', '/', $path);

        if (str_starts_with($normalized_path_, $this->base_path_normalized_.'/')) {
            return substr($normalized_path_, strlen($this->base_path_normalized_) + 1);
        }

        return $normalized_path_;
    }

    /**
     * @return array<string, mixed>
     */
    public function Json_File_Resolve(string $path): array
    {
        if (! $this->files_->exists($path)) {
            return [];
        }

        $decoded_ = json_decode($this->files_->get($path), true);

        return is_array($decoded_) ? $decoded_ : [];
    }

    /**
     * @return array<int, string>
     */
    public function Classmap_Roots_Resolve(): array
    {
        $composer_ = $this->Json_File_Resolve(base_path('composer.json'));
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
    public function Node_Directories_From_Root_Resolve(string $classmap_root): array
    {
        $absolute_root_path_ = base_path($classmap_root);
        $node_directories_ = [];

        $candidate_directories_ = [$absolute_root_path_, ...$this->files_->allDirectories($absolute_root_path_)];

        foreach ($candidate_directories_ as $directory_path_) {
            $relative_directory_path_ = $this->Relative_Path_From_Absolute_Path($directory_path_);

            if (! $this->Is_Node_Like_Directory($relative_directory_path_)) {
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
    public function Node_Directory_Files_Resolve(string $node_directory): array
    {
        $absolute_node_directory_ = base_path($node_directory);
        $file_paths_ = [];

        if (! $this->files_->isDirectory($absolute_node_directory_)) {
            return [];
        }

        foreach ($this->files_->allFiles($absolute_node_directory_) as $file_) {
            $file_paths_[] = $this->Relative_Path($file_);
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
    public function Node_Files_Categorize(array $file_paths): array
    {
        $categorized_files_ = [
            'controllers' => [],
            'views' => [],
            'configs' => [],
            'models' => [],
            'tests' => [],
            'others' => [],
        ];

        foreach ($file_paths as $file_path_) {
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
    public function Tree_Lines_Resolve(string $node_directory, array $file_paths): array
    {
        $relative_entries_ = [];

        foreach ($file_paths as $file_path_) {
            $relative_file_path_ = ltrim(substr($file_path_, strlen($node_directory)), '/');

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

        $lines_ = [basename($node_directory)];
        $this->Tree_Lines_Append($lines_, $relative_entries_, '', 0);

        return $lines_;
    }

    /**
     * @param  array<int, string>  $lines_
     * @param  array<string, string>  $relative_entries_
     */
    public function Tree_Lines_Append(array &$lines, array $relative_entries, string $parent_path, int $depth): void
    {
        $children_ = [];

        foreach ($relative_entries as $entry_path_ => $entry_type_) {
            $normalized_parent_ = $parent_path === '' ? '' : $parent_path.'/';

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
            $indent_ = str_repeat('  ', $depth + 1);
            $lines[] = $indent_.'|-- '.basename($child_path_);

            if ($child_type_ === 'dir') {
                $this->Tree_Lines_Append($lines, $relative_entries, $child_path_, $depth + 1);
            }
        }
    }

    public function Relative_Path_From_Absolute_Path(string $absolute_path): string
    {
        return ltrim(str_replace($this->base_path_normalized_, '', str_replace('\\', '/', $absolute_path)), '/');
    }

    public function Is_Node_Like_Directory(string $relative_directory_path): bool
    {
        $absolute_directory_path_ = base_path($relative_directory_path);
        $allowed_role_directories_ = ['controller', 'config', 'view', 'test', 'model'];
        $normalized_directory_path_ = str_replace('\\', '/', $relative_directory_path);

        if (! $this->files_->isDirectory($absolute_directory_path_)) {
            return false;
        }

        if (! str_starts_with($normalized_directory_path_, 'code/page/')
            && ! str_starts_with($normalized_directory_path_, 'code/template/')
            && ! str_starts_with($normalized_directory_path_, 'template/page/node/')
            && ! str_starts_with($normalized_directory_path_, 'code/test/node_fixture/')) {
            return false;
        }

        if (basename($absolute_directory_path_) === 'node') {
            foreach ($this->files_->allFiles($absolute_directory_path_) as $file_) {
                if ($this->Is_Node_Like_File_Path($this->Relative_Path($file_))) {
                    return true;
                }
            }

            return false;
        }

        foreach ($this->files_->files($absolute_directory_path_) as $file_) {
            if ($this->Is_Node_Like_File_Path($this->Relative_Path($file_))) {
                return true;
            }
        }

        $direct_child_directories_ = $this->files_->directories($absolute_directory_path_);
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
            foreach ($this->files_->allFiles($directory_path_) as $file_) {
                if ($this->Is_Node_Like_File_Path($this->Relative_Path($file_))) {
                    return true;
                }
            }
        }

        return false;
    }

    public function Is_Node_Like_File_Path(string $file_path): bool
    {
        $basename_ = basename($file_path);

        return preg_match('/^hahaha_(controller|view|config|model|test)_.+\.(?:php|blade\.php)$/', $basename_) === 1;
    }

    public function Is_Absolute_Path(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path) === 1;
    }
}
