<?php

namespace L_Lib\Providers;

use Illuminate\Support\ServiceProvider;
use L_Lib\Console\Commands\apache\hahaha_command_create_vhost;
use L_Lib\Console\Commands\apache\hahaha_command_create_proxy_vhost;
use L_Lib\Console\Commands\ai\hahaha_cache_ai_context;
use L_Lib\Console\Commands\ai\hahaha_cache_code_summary;
use L_Lib\Console\Commands\ai\hahaha_cache_project_structure;
use L_Lib\Console\Commands\ai\node\hahaha_cache_node_project_analysis;
use L_Lib\Console\Commands\ai\node\hahaha_cache_work_target_analysis;
use L_Lib\Console\Commands\db\hahaha_command_create_database;
use L_Lib\Console\Commands\db\hahaha_command_db_table_enum_generate;
use L_Lib\Console\Commands\db\hahaha_command_delete_database;
use L_Lib\Console\Commands\db\hahaha_command_rename_database;
use L_Lib\Console\Commands\git\hahaha_command_clone;
use L_Lib\Console\Commands\git\hahaha_command_pull;
use L_Lib\Providers\agents\skills\hahaha_provider_agents_skills;

/**
 * 主 service provider，負責彙整 library 內各領域 provider 與 command 註冊。
 */
class hahaha_laravel_lib_service_provider extends ServiceProvider
{
    /**
     * 供後續需要時放置 container binding。
     */
    public function register(): void
    {
        //
    }

    /**
     * 於 console 環境註冊本 library 需要的 artisan commands。
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands($this->Console_Commands_Resolve());
        }
    }

    /**
     * 彙整主 library 與各子領域 provider 的 command 清單。
     *
     * @return array<int, class-string>
     */
    public function Console_Commands_Resolve(): array
    {
        return array_merge([
            hahaha_command_create_proxy_vhost::class,
            hahaha_command_create_vhost::class,
            hahaha_cache_ai_context::class,
            hahaha_cache_code_summary::class,
            hahaha_cache_project_structure::class,
            hahaha_cache_node_project_analysis::class,
            hahaha_cache_work_target_analysis::class,
            hahaha_command_db_table_enum_generate::class,
            hahaha_command_create_database::class,
            hahaha_command_delete_database::class,
            hahaha_command_rename_database::class,
            hahaha_command_clone::class,
            hahaha_command_pull::class,
        ], hahaha_provider_agents_skills::Commands_Resolve());
    }
}
