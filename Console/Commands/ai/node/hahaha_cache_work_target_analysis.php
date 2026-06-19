<?php

namespace L_Lib\Console\Commands\ai\node;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use SplFileInfo;

#[Signature('app:hahaha-cache-work-target-analysis
    {--output-dir=storage/app/ai-context/node : Directory used to store work-target analysis cache files}
    {--force : Rebuild the cache even when the fingerprint is unchanged}', aliases: ['l_lib:app:hahaha-cache-work-target-analysis'])]
#[Description('為 Codex 產生以需求定位為主的精簡工作目標分析摘要')]
class hahaha_cache_work_target_analysis extends Command
{
    public const DEFAULT_OUTPUT_DIR = 'storage/app/ai-context/node';

    public const MARKDOWN_FILE = 'work-target-analysis.md';

    public const JSON_FILE = 'work-target-analysis.json';

    public const META_FILE = '.hahaha_cache_work_target_analysis.meta.json';

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
        'code/',
        'config/',
        'database/',
        'library/hahaha_laravel_lib/',
        'resources/',
        'routes/',
        'template/',
        'tests/',
        'tool/',
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

        $files_ = $this->Collect_Relevant_Files($output_dir_);
        $fingerprint_ = $this->Build_Fingerprint($files_);

        if (! (bool) $this->option('force') && $this->Is_Fingerprint_Unchanged($output_dir_, $fingerprint_)) {
            $this->components->info(sprintf('程式碼未變更，略過重建：%s', $this->Display_Path($output_dir_)));

            return self::SUCCESS;
        }

        $analysis_ = $this->Analysis_Resolve($files_);

        $this->Write_File(
            $output_dir_.DIRECTORY_SEPARATOR.self::MARKDOWN_FILE,
            $this->Markdown_Render($analysis_)
        );
        $this->Write_File(
            $output_dir_.DIRECTORY_SEPARATOR.self::JSON_FILE,
            json_encode($analysis_, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL
        );
        $this->Meta_Write($output_dir_, $fingerprint_, $analysis_);

        $this->components->info(sprintf('Work target 分析快取已輸出：%s', $this->Display_Path($output_dir_)));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array{path: string, size: int, modified_at: string, extension: string}>  $files_
     * @return array{
     *     generated_at: string,
     *     summary: array{target_count: int, target_with_routes_count: int, target_with_tests_count: int},
     *     targets: array<int, array{
     *         key: string,
     *         primary_path: string,
     *         areas: array<int, string>,
     *         recent_modified_at: string,
     *         route_count: int,
     *         routes: array<int, array{methods: string, uri: string, name: string, action: string}>,
     *         files: array{
     *             routes: array<int, string>,
     *             controllers: array<int, string>,
     *             configs: array<int, string>,
     *             views: array<int, string>,
     *             tests: array<int, string>,
     *             others: array<int, string>
     *         },
     *         open_order: array<int, string>
     *     }>
     * }
     */
    public function Analysis_Resolve(array $files): array
    {
            $route_index_ = $this->Route_Index_Resolve();
        $targets_by_key_ = [];

        foreach ($files as $file_) {
            $target_reference_ = $this->Target_Reference_Resolve($file_['path']);
            if ($target_reference_ === null) {
                continue;
            }

            $file_role_ = $this->File_Role_Resolve($file_['path']);
            if ($file_role_ === null) {
                continue;
            }

            $target_key_ = $target_reference_['key'];
            if (! isset($targets_by_key_[$target_key_])) {
                $targets_by_key_[$target_key_] = [
                    'key' => $target_key_,
                    'primary_path' => $target_reference_['primary_path'],
                    'areas' => [],
                    'recent_modified_at' => $file_['modified_at'],
                    'route_count' => 0,
                    'routes' => [],
                    'files' => [
                        'routes' => [],
                        'controllers' => [],
                        'configs' => [],
                        'views' => [],
                        'tests' => [],
                        'others' => [],
                    ],
                    'controller_classes' => [],
                ];
            }

            if ($this->Primary_Path_Priority_Resolve($file_role_) < $this->Primary_Path_Priority_Resolve_From_Path($targets_by_key_[$target_key_]['primary_path'])) {
                $targets_by_key_[$target_key_]['primary_path'] = $target_reference_['primary_path'];
            }

            $targets_by_key_[$target_key_]['areas'][] = explode('/', $file_['path'])[0];
            $targets_by_key_[$target_key_]['recent_modified_at'] = max($targets_by_key_[$target_key_]['recent_modified_at'], $file_['modified_at']);
            $targets_by_key_[$target_key_]['files'][$file_role_][] = $file_['path'];

            if ($file_role_ === 'controllers') {
                $controller_class_ = $this->Controller_Class_Resolve($file_['path']);
                if ($controller_class_ !== null) {
                    $targets_by_key_[$target_key_]['controller_classes'][] = $controller_class_;
                }
            }
        }

        foreach ($targets_by_key_ as &$target_) {
            $target_['areas'] = array_values(array_unique($target_['areas']));
            sort($target_['areas']);

            foreach ($target_['files'] as &$file_group_) {
                $file_group_ = array_values(array_unique($file_group_));
                sort($file_group_);
            }
            unset($file_group_);

            $routes_ = [];
            foreach (array_values(array_unique($target_['controller_classes'])) as $controller_class_) {
                foreach ($route_index_[$controller_class_] ?? [] as $route_item_) {
                    $routes_[] = $route_item_;
                }
            }

            usort($routes_, static fn (array $left_, array $right_): int => strcmp($left_['uri'], $right_['uri']));
            $routes_ = array_values(array_unique($routes_, SORT_REGULAR));

            $target_['routes'] = $routes_;
            $target_['route_count'] = count($routes_);
            $target_['open_order'] = $this->Open_Order_Resolve($target_['files']);

            unset($target_['controller_classes']);
        }
        unset($target_);

        $targets_ = array_values($targets_by_key_);
        usort($targets_, static function (array $left_, array $right_): int {
            $modified_compare_ = strcmp($right_['recent_modified_at'], $left_['recent_modified_at']);

            if ($modified_compare_ !== 0) {
                return $modified_compare_;
            }

            return strcmp($left_['key'], $right_['key']);
        });

        return [
            'generated_at' => Carbon::now()->toDateTimeString(),
            'summary' => [
                'target_count' => count($targets_),
                'target_with_routes_count' => count(array_filter($targets_, static fn (array $target_): bool => $target_['route_count'] > 0)),
                'target_with_tests_count' => count(array_filter($targets_, static fn (array $target_): bool => $target_['files']['tests'] !== [])),
            ],
            'targets' => $targets_,
        ];
    }

    /**
     * @return array<string, array<int, array{methods: string, uri: string, name: string, action: string}>>
     */
    public function Route_Index_Resolve(): array
    {
        $index_ = [];

        foreach (Route::getRoutes()->getRoutes() as $route_) {
            $action_name_ = $route_->getActionName();

            if (! is_string($action_name_) || ! str_contains($action_name_, '@')) {
                continue;
            }

            [$controller_class_] = explode('@', $action_name_, 2);

            $index_[$controller_class_] ??= [];
            $index_[$controller_class_][] = [
                'methods' => implode('|', array_values(array_filter($route_->methods(), static fn (string $method_): bool => $method_ !== 'HEAD'))),
                'uri' => $route_->uri(),
                'name' => $route_->getName() ?? '-',
                'action' => $action_name_,
            ];
        }

        return $index_;
    }

    public function File_Role_Resolve(string $path): ?string
    {
        $normalized_path_ = str_replace('\\', '/', $path);
        $basename_ = basename($normalized_path_);

        if (str_ends_with($basename_, '.blade.php')) {
            return 'views';
        }

        if ($basename_ === 'Controller.php') {
            return null;
        }

        if (str_starts_with($normalized_path_, 'app/Http/Controllers/') || str_contains($normalized_path_, '/controller/')) {
            return 'controllers';
        }

        if (preg_match('/^hahaha_route_.+\.php$/', $basename_) === 1) {
            return 'routes';
        }

        if (preg_match('/^hahaha_config_.+\.php$/', $basename_) === 1 || str_contains($normalized_path_, '/config/')) {
            return 'configs';
        }

        if (preg_match('/^hahaha_test_.+\.php$/', $basename_) === 1 || str_contains($normalized_path_, '/test/')) {
            return 'tests';
        }

        if (preg_match('/^hahaha_(model|service)_.+\.php$/', $basename_) === 1) {
            return 'others';
        }

        return null;
    }

    /**
     * @return array{key: string, primary_path: string}|null
     */
    public function Target_Reference_Resolve(string $path): ?array
    {
        $normalized_path_ = str_replace('\\', '/', $path);
        $segments_ = explode('/', $normalized_path_);
        $basename_ = basename($normalized_path_);

        if (str_starts_with($normalized_path_, 'tool/page/') && count($segments_) >= 3) {
            return [
                'key' => $segments_[2],
                'primary_path' => implode('/', array_slice($segments_, 0, 3)),
            ];
        }

        if (str_starts_with($normalized_path_, 'template/page/node/') && count($segments_) >= 4) {
            return [
                'key' => $segments_[3],
                'primary_path' => implode('/', array_slice($segments_, 0, 4)),
            ];
        }

        if (str_starts_with($normalized_path_, 'app/Http/Controllers/') && count($segments_) >= 5) {
            return [
                'key' => $segments_[count($segments_) - 2],
                'primary_path' => implode('/', array_slice($segments_, 0, count($segments_) - 1)),
            ];
        }

        if (preg_match('/^hahaha_(?:route|controller|config|view|test|model|service)_(.+?)(?:\.blade)?\.php$/', $basename_, $matches_) === 1) {
            return [
                'key' => $matches_[1],
                'primary_path' => dirname($normalized_path_) === '.' ? $normalized_path_ : dirname($normalized_path_),
            ];
        }

        if (preg_match('/^hahaha_(.+?)_(?:route|controller|config|view|test|model|service)\.php$/', $basename_, $matches_) === 1) {
            return [
                'key' => $matches_[1],
                'primary_path' => dirname($normalized_path_) === '.' ? $normalized_path_ : dirname($normalized_path_),
            ];
        }

        if (str_starts_with($normalized_path_, 'resources/views/')) {
            return [
                'key' => str_replace('.blade.php', '', $basename_),
                'primary_path' => dirname($normalized_path_),
            ];
        }

        if (str_starts_with($normalized_path_, 'code/config/') && preg_match('/^hahaha_config_(.+)\.php$/', $basename_, $matches_) === 1) {
            return [
                'key' => $matches_[1],
                'primary_path' => dirname($normalized_path_),
            ];
        }

        return null;
    }

    public function Controller_Class_Resolve(string $path): ?string
    {
        $absolute_path_ = base_path($path);

        if (! $this->files_->exists($absolute_path_)) {
            return null;
        }

        $contents_ = $this->files_->get($absolute_path_);

        if (preg_match('/^namespace\s+([^;]+);/m', $contents_, $namespace_match_) !== 1) {
            return null;
        }

        if (preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)\b/m', $contents_, $class_match_) !== 1) {
            return null;
        }

        return trim($namespace_match_[1]).'\\'.trim($class_match_[1]);
    }

    /**
     * @param  array{
     *     routes: array<int, string>,
     *     controllers: array<int, string>,
     *     configs: array<int, string>,
     *     views: array<int, string>,
     *     tests: array<int, string>,
     *     others: array<int, string>
     * }  $files_
     * @return array<int, string>
     */
    public function Open_Order_Resolve(array $files): array
    {
        return array_values(array_merge(
            $files['routes'],
            $files['controllers'],
            $files['configs'],
            $files['views'],
            $files['tests']
        ));
    }

    /**
     * @param  array{
     *     generated_at: string,
     *     summary: array{target_count: int, target_with_routes_count: int, target_with_tests_count: int},
     *     targets: array<int, array{
     *         key: string,
     *         primary_path: string,
     *         areas: array<int, string>,
     *         recent_modified_at: string,
     *         route_count: int,
     *         routes: array<int, array{methods: string, uri: string, name: string, action: string}>,
     *         files: array{
     *             routes: array<int, string>,
     *             controllers: array<int, string>,
     *             configs: array<int, string>,
     *             views: array<int, string>,
     *             tests: array<int, string>,
     *             others: array<int, string>
     *         },
     *         open_order: array<int, string>
     *     }>
     * } $analysis_
     */
    public function Markdown_Render(array $analysis): string
    {
        $lines_ = [
            '# Work Target Analysis',
            '',
            'Generated at: '.$analysis['generated_at'],
            '- Target count: '.$analysis['summary']['target_count'],
            '- Targets with routes: '.$analysis['summary']['target_with_routes_count'],
            '- Targets with tests: '.$analysis['summary']['target_with_tests_count'],
            '',
        ];

        foreach ($analysis['targets'] as $target_) {
            $lines_[] = '## '.$target_['key'];
            $lines_[] = '';
            $lines_[] = '- Primary path: '.$target_['primary_path'];
            $lines_[] = '- Areas: '.implode(', ', $target_['areas']);
            $lines_[] = '- Recent modified at: '.$target_['recent_modified_at'];
            $lines_[] = '- Route count: '.$target_['route_count'];
            $lines_[] = '- Open order: '.implode(' -> ', $target_['open_order']);

            if ($target_['routes'] !== []) {
                $lines_[] = '- Routes:';
                foreach ($target_['routes'] as $route_) {
                    $lines_[] = sprintf('  - [%s] %s (%s) => %s', $route_['methods'], $route_['uri'], $route_['name'], $route_['action']);
                }
            }

            foreach (['routes', 'controllers', 'configs', 'views', 'tests', 'others'] as $group_) {
                if ($target_['files'][$group_] === []) {
                    continue;
                }

                $lines_[] = '- '.ucfirst($group_).':';
                foreach ($target_['files'][$group_] as $path_) {
                    $lines_[] = '  - '.$path_;
                }
            }

            $lines_[] = '';
        }

        return implode(PHP_EOL, $lines_).PHP_EOL;
    }

    public function Primary_Path_Priority_Resolve(string $file_role): int
    {
        return match ($file_role) {
            'routes' => 1,
            'controllers' => 2,
            'configs' => 3,
            'views' => 4,
            'tests' => 5,
            default => 6,
        };
    }

    public function Primary_Path_Priority_Resolve_From_Path(string $path): int
    {
        return $this->Primary_Path_Priority_Resolve($this->File_Role_Resolve($path) ?? 'others');
    }

    /**
     * @return array<int, array{path: string, size: int, modified_at: string, extension: string}>
     */
    public function Collect_Relevant_Files(string $output_dir): array
    {
        $output_dir_normalized_ = str_replace('\\', '/', rtrim($output_dir, '\\/'));
        $files_ = [];

        foreach ($this->files_->allFiles(base_path()) as $file_) {
            $path_ = $this->Relative_Path($file_);

            if (! $this->Is_Relevant_Path($path_)) {
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

        return $files_;
    }

    public function Is_Relevant_Path(string $path): bool
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

        return in_array($path, ['composer.json', 'package.json', 'phpunit.xml', 'artisan'], true);
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
     *     summary: array{target_count: int, target_with_routes_count: int, target_with_tests_count: int},
     *     targets: array<int, array{
     *         key: string,
     *         primary_path: string,
     *         areas: array<int, string>,
     *         recent_modified_at: string,
     *         route_count: int,
     *         routes: array<int, array{methods: string, uri: string, name: string, action: string}>,
     *         files: array{
     *             routes: array<int, string>,
     *             controllers: array<int, string>,
     *             configs: array<int, string>,
     *             views: array<int, string>,
     *             tests: array<int, string>,
     *             others: array<int, string>
     *         },
     *         open_order: array<int, string>
     *     }>
     * } $analysis_
     */
    public function Meta_Write(string $output_dir, string $fingerprint, array $analysis): void
    {
        $meta_path_ = rtrim($output_dir, '\\/').DIRECTORY_SEPARATOR.self::META_FILE;

        $this->files_->put($meta_path_, json_encode([
            'fingerprint' => $fingerprint,
            'updated_at' => $analysis['generated_at'],
            'files' => [
                'markdown' => self::MARKDOWN_FILE,
                'json' => self::JSON_FILE,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL);
    }

    public function Write_File(string $path, string $contents): void
    {
        if ($this->files_->exists($path) && $this->files_->get($path) === $contents) {
            return;
        }

        $this->files_->put($path, $contents);
    }

    public function Resolve_Output_Dir(string $output_dir): string
    {
        $normalized_ = trim($output_dir);

        if ($normalized_ === '') {
            $normalized_ = self::DEFAULT_OUTPUT_DIR;
        }

        return $this->Is_Absolute_Path($normalized_) ? $normalized_ : base_path($normalized_);
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

    public function Is_Absolute_Path(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2}|\/)/', $path) === 1;
    }
}
