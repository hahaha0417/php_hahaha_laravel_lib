<?php

namespace L_Lib\Tests;

use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class hahaha_test_cache_node_project_analysis_command_Test extends TestCase
{
    public string $output_dir_;

    public string $node_fixture_root_dir_;

    public string $node_fixture_dir_;

    public string $node_fixture_relative_dir_;

    public function setUp(): void
    {
        parent::setUp();

        $parallel_token_ = (string) (ParallelTesting::token() ?? 'single');
        $test_name_ = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $this->name()));
        $this->output_dir_ = storage_path('app/testing-ai-context/node-project-analysis-'.$parallel_token_.'-'.$test_name_);
        $this->node_fixture_root_dir_ = base_path('code/test/node_fixture');
        $this->node_fixture_dir_ = $this->node_fixture_root_dir_.DIRECTORY_SEPARATOR.'product_'.$parallel_token_.'_'.$test_name_;
        $this->node_fixture_relative_dir_ = 'code/test/node_fixture/'.basename($this->node_fixture_dir_);

        File::deleteDirectory($this->output_dir_);
        File::deleteDirectory($this->node_fixture_dir_);
    }

    public function tearDown(): void
    {
        File::deleteDirectory($this->output_dir_);

        parent::tearDown();
    }

    public function test_it_generates_node_project_analysis_cache_files(): void
    {
        $this->Node_Fixture_Create();

        $this->artisan('app:hahaha-cache-node-project-analysis', [
            '--output-dir' => $this->output_dir_,
            '--with-tests' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('Node / Codex 專案分析快取已輸出')
            ->assertExitCode(0);

        $markdown_path_ = $this->output_dir_.DIRECTORY_SEPARATOR.'project-analysis.md';
        $json_path_ = $this->output_dir_.DIRECTORY_SEPARATOR.'project-analysis.json';
        $page_node_markdown_path_ = $this->output_dir_.DIRECTORY_SEPARATOR.'page-node-analysis.md';
        $page_node_json_path_ = $this->output_dir_.DIRECTORY_SEPARATOR.'page-node-analysis.json';
        $meta_path_ = $this->output_dir_.DIRECTORY_SEPARATOR.'.hahaha_cache_node_project_analysis.meta.json';

        $this->assertFileExists($markdown_path_);
        $this->assertFileExists($json_path_);
        $this->assertFileExists($page_node_markdown_path_);
        $this->assertFileExists($page_node_json_path_);
        $this->assertFileExists($meta_path_);

        $this->assertStringContainsString('# Node Project Analysis', File::get($markdown_path_));
        $this->assertStringContainsString('# Page Node Analysis', File::get($page_node_markdown_path_));

        $payload_ = json_decode(File::get($json_path_), true);
        $page_node_payload_ = json_decode(File::get($page_node_json_path_), true);

        $this->assertIsArray($payload_);
        $this->assertIsArray($page_node_payload_);
        $this->assertSame(base_path(), $payload_['project']['root'] ?? null);
        $this->assertIsInt($payload_['summary']['relevant_file_count'] ?? null);
        $this->assertArrayHasKey('routes', $payload_);
        $this->assertArrayHasKey('database', $payload_);
        $this->assertContains('code', $payload_['classmap']['roots'] ?? []);
        $this->assertGreaterThanOrEqual(1, $page_node_payload_['node_directory_count'] ?? 0);
        $this->assertContains($this->node_fixture_relative_dir_, array_column($page_node_payload_['node_directories'] ?? [], 'path'));
        $fixture_node_directory_index_ = array_search($this->node_fixture_relative_dir_, array_column($page_node_payload_['node_directories'] ?? [], 'path'), true);
        $fixture_node_directory_ = $page_node_payload_['node_directories'][$fixture_node_directory_index_] ?? [];
        $this->assertContains($this->node_fixture_relative_dir_.'/hahaha_controller_product.php', $fixture_node_directory_['controllers'] ?? []);
        $this->assertContains($this->node_fixture_relative_dir_.'/hahaha_view_product.blade.php', $fixture_node_directory_['views'] ?? []);
        $this->assertContains($this->node_fixture_relative_dir_.'/hahaha_config_product.php', $fixture_node_directory_['configs'] ?? []);
        $this->assertContains($this->node_fixture_relative_dir_.'/hahaha_test_product.php', $fixture_node_directory_['tests'] ?? []);
        $this->assertContains($this->node_fixture_relative_dir_.'/hahaha_service_product.php', $fixture_node_directory_['others'] ?? []);
        $this->assertContains($this->node_fixture_relative_dir_.'/hahaha_test_product.php', $payload_['tests']['items'] ?? []);
    }

    public function test_it_skips_rebuild_when_fingerprint_is_unchanged(): void
    {
        $this->Node_Fixture_Create();

        $this->artisan('app:hahaha-cache-node-project-analysis', [
            '--output-dir' => $this->output_dir_,
            '--force' => true,
        ])->assertExitCode(0);

        $this->artisan('app:hahaha-cache-node-project-analysis', [
            '--output-dir' => $this->output_dir_,
        ])->assertExitCode(0);
    }

    public function Node_Fixture_Create(): void
    {
        File::ensureDirectoryExists($this->node_fixture_dir_);

        File::put($this->node_fixture_dir_.DIRECTORY_SEPARATOR.'hahaha_controller_product.php', "<?php\n\nclass hahaha_controller_product {}\n");
        File::put($this->node_fixture_dir_.DIRECTORY_SEPARATOR.'hahaha_view_product.blade.php', "<div>product</div>\n");
        File::put($this->node_fixture_dir_.DIRECTORY_SEPARATOR.'hahaha_config_product.php', "<?php\n\nclass hahaha_config_product {}\n");
        File::put($this->node_fixture_dir_.DIRECTORY_SEPARATOR.'hahaha_test_product.php', "<?php\n\nclass hahaha_test_product {}\n");
        File::put($this->node_fixture_dir_.DIRECTORY_SEPARATOR.'hahaha_service_product.php', "<?php\n\nclass hahaha_service_product {}\n");
    }
}
