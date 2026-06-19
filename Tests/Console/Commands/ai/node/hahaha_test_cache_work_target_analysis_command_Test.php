<?php

namespace L_Lib\Tests;

use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class hahaha_test_cache_work_target_analysis_command_Test extends TestCase
{
    public string $output_dir_;

    public string $fixture_relative_dir_;

    public string $fixture_dir_;

    public string $fixture_key_;

    public function setUp(): void
    {
        parent::setUp();

        $parallel_token_ = (string) (ParallelTesting::token() ?? 'single');
        $test_name_ = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $this->name()));
        $this->output_dir_ = storage_path('app/testing-ai-context/work-target-analysis-'.$parallel_token_.'-'.$test_name_);
        $this->fixture_relative_dir_ = 'tool/page/testing_target_'.$parallel_token_.'_'.$test_name_;
        $this->fixture_dir_ = base_path($this->fixture_relative_dir_);
        $this->fixture_key_ = basename($this->fixture_relative_dir_);

        File::deleteDirectory($this->output_dir_);
        File::deleteDirectory($this->fixture_dir_);
    }

    public function tearDown(): void
    {
        File::deleteDirectory($this->output_dir_);

        parent::tearDown();
    }

    public function test_it_generates_work_target_analysis_cache_files(): void
    {
        $this->Fixture_Create();

        $this->artisan('app:hahaha-cache-work-target-analysis', [
            '--output-dir' => $this->output_dir_,
            '--force' => true,
        ])
            ->expectsOutputToContain('Work target 分析快取已輸出')
            ->assertExitCode(0);

        $markdown_path_ = $this->output_dir_.DIRECTORY_SEPARATOR.'work-target-analysis.md';
        $json_path_ = $this->output_dir_.DIRECTORY_SEPARATOR.'work-target-analysis.json';
        $meta_path_ = $this->output_dir_.DIRECTORY_SEPARATOR.'.hahaha_cache_work_target_analysis.meta.json';

        $this->assertFileExists($markdown_path_);
        $this->assertFileExists($json_path_);
        $this->assertFileExists($meta_path_);
        $this->assertStringContainsString('# Work Target Analysis', File::get($markdown_path_));

        $payload_ = json_decode(File::get($json_path_), true);

        $this->assertIsArray($payload_);
        $this->assertArrayHasKey('summary', $payload_);
        $this->assertArrayHasKey('targets', $payload_);

        $target_keys_ = array_column($payload_['targets'] ?? [], 'key');
        $this->assertContains($this->fixture_key_, $target_keys_);
        $this->assertContains('queue_viewer', $target_keys_);
        $this->assertContains('animal', $target_keys_);

        $fixture_target_index_ = array_search($this->fixture_key_, $target_keys_, true);
        $fixture_target_ = $payload_['targets'][$fixture_target_index_] ?? [];

        $this->assertSame($this->fixture_relative_dir_, $fixture_target_['primary_path'] ?? null);
        $this->assertContains($this->fixture_relative_dir_.'/route/hahaha_route_testing_target.php', $fixture_target_['files']['routes'] ?? []);
        $this->assertContains($this->fixture_relative_dir_.'/controller/hahaha_controller_testing_target.php', $fixture_target_['files']['controllers'] ?? []);
        $this->assertContains($this->fixture_relative_dir_.'/config/hahaha_config_testing_target.php', $fixture_target_['files']['configs'] ?? []);
        $this->assertContains($this->fixture_relative_dir_.'/view/hahaha_view_testing_target.blade.php', $fixture_target_['files']['views'] ?? []);
        $this->assertContains($this->fixture_relative_dir_.'/test/hahaha_test_testing_target.php', $fixture_target_['files']['tests'] ?? []);
        $this->assertSame([
            $this->fixture_relative_dir_.'/route/hahaha_route_testing_target.php',
            $this->fixture_relative_dir_.'/controller/hahaha_controller_testing_target.php',
            $this->fixture_relative_dir_.'/config/hahaha_config_testing_target.php',
            $this->fixture_relative_dir_.'/view/hahaha_view_testing_target.blade.php',
            $this->fixture_relative_dir_.'/test/hahaha_test_testing_target.php',
        ], $fixture_target_['open_order'] ?? []);
    }

    public function test_it_skips_rebuild_when_fingerprint_is_unchanged(): void
    {
        $this->Fixture_Create();

        $this->artisan('app:hahaha-cache-work-target-analysis', [
            '--output-dir' => $this->output_dir_,
            '--force' => true,
        ])->assertExitCode(0);

        $this->artisan('app:hahaha-cache-work-target-analysis', [
            '--output-dir' => $this->output_dir_,
        ])->assertExitCode(0);
    }

    public function Fixture_Create(): void
    {
        File::ensureDirectoryExists($this->fixture_dir_.DIRECTORY_SEPARATOR.'route');
        File::ensureDirectoryExists($this->fixture_dir_.DIRECTORY_SEPARATOR.'controller');
        File::ensureDirectoryExists($this->fixture_dir_.DIRECTORY_SEPARATOR.'config');
        File::ensureDirectoryExists($this->fixture_dir_.DIRECTORY_SEPARATOR.'view');
        File::ensureDirectoryExists($this->fixture_dir_.DIRECTORY_SEPARATOR.'test');

        File::put($this->fixture_dir_.DIRECTORY_SEPARATOR.'route/hahaha_route_testing_target.php', "<?php\n\nclass hahaha_route_testing_target {}\n");
        File::put($this->fixture_dir_.DIRECTORY_SEPARATOR.'controller/hahaha_controller_testing_target.php', "<?php\n\nnamespace hahaha\\tool\\page\\testing_target;\n\nclass hahaha_controller_testing_target {}\n");
        File::put($this->fixture_dir_.DIRECTORY_SEPARATOR.'config/hahaha_config_testing_target.php', "<?php\n\nclass hahaha_config_testing_target {}\n");
        File::put($this->fixture_dir_.DIRECTORY_SEPARATOR.'view/hahaha_view_testing_target.blade.php', "<div>testing target</div>\n");
        File::put($this->fixture_dir_.DIRECTORY_SEPARATOR.'test/hahaha_test_testing_target.php', "<?php\n\nclass hahaha_test_testing_target {}\n");
    }
}
