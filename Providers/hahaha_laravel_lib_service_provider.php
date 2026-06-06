<?php

namespace L_Lib\Providers;

use Illuminate\Support\ServiceProvider;
use L_Lib\Console\Commands\ai\hahaha_cache_ai_context;
use L_Lib\Console\Commands\ai\hahaha_cache_code_summary;
use L_Lib\Console\Commands\ai\hahaha_cache_project_structure;
use L_Lib\Console\Commands\db\hahaha_command_create_database;
use L_Lib\Console\Commands\db\hahaha_command_db_table_enum_generate;
use L_Lib\Console\Commands\db\hahaha_command_delete_database;
use L_Lib\Console\Commands\db\hahaha_command_rename_database;

class hahaha_laravel_lib_service_provider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                hahaha_cache_ai_context::class,
                hahaha_cache_code_summary::class,
                hahaha_cache_project_structure::class,
                hahaha_command_db_table_enum_generate::class,
                hahaha_command_create_database::class,
                hahaha_command_delete_database::class,
                hahaha_command_rename_database::class,
            ]);
        }
    }
}
