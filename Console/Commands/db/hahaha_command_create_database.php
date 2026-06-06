<?php

namespace L_Lib\Console\Commands\db;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class hahaha_command_create_database extends Command
{
    private const TEMP_CONNECTION_NAME = 'hahaha_install_database_create_';

    protected $signature = 'l_lib:db:create_database
        {--database= : The database name to create}
        {--connection= : The database connection name to use}
        {--force=2 : 1 forces creation, 2 requires confirmation when the database already exists}';

    protected $description = 'Create the configured database using the Laravel database configuration';

    public function handle(): int
    {
        $database_option_ = trim((string) $this->option('database'));
        $connection_option_ = trim((string) $this->option('connection'));
        $force_option_ = trim((string) $this->option('force'));
        $connection_name_ = $connection_option_ !== '' ? $connection_option_ : (string) config('database.default', 'sqlite');
        $connection_config_ = config('database.connections.'.$connection_name_);

        if (! in_array($force_option_, ['1', '2'], true)) {
            $this->components->error('The --force option must be 1 or 2.');

            return self::FAILURE;
        }

        if (! is_array($connection_config_)) {
            $this->components->error('Database connection is not configured: '.$connection_name_);

            return self::FAILURE;
        }

        if ($database_option_ !== '') {
            $connection_config_['database'] = $database_option_;
        }

        $database_connection_ = (string) ($connection_config_['driver'] ?? '');

        try {
            return match ($database_connection_) {
                'sqlite' => $this->sqlite_database_create_($connection_config_),
                'mysql', 'mariadb', 'pgsql', 'sqlsrv' => $this->server_database_create_($connection_config_),
                default => $this->database_connection_unsupported_($database_connection_),
            };
        } catch (Throwable $throwable_) {
            $this->components->error($throwable_->getMessage());

            return self::FAILURE;
        }
    }

    private function database_connection_unsupported_(string $database_connection_): int
    {
        $this->components->error('Unsupported DB_CONNECTION value: '.$database_connection_);

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    private function sqlite_database_create_(array $connection_config_): int
    {
        $database_path_input_ = (string) ($connection_config_['database'] ?? '');

        if ($database_path_input_ === '') {
            $this->components->error('DB_DATABASE must be set for sqlite connections.');

            return self::FAILURE;
        }

        if ($database_path_input_ === ':memory:') {
            $this->components->info('SQLite in-memory database does not need to be created.');

            return self::SUCCESS;
        }

        $database_path_ = $this->sqlite_database_path_resolve_($database_path_input_);
        $database_directory_ = dirname($database_path_);

        if (! File::isDirectory($database_directory_)) {
            File::makeDirectory($database_directory_, 0755, true);
        }

        $connection_config_['database'] = $database_path_;

        if ($this->sqlite_database_exists_($database_path_)) {
            $this->components->warn('Database already exists: '.$database_path_);

            return self::FAILURE;
        }

        if (! $this->database_creation_should_continue_($database_path_)) {
            return self::FAILURE;
        }

        $this->database_create_with_schema_builder_($connection_config_, $database_path_);

        $this->components->info('Database created at '.$database_path_);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    private function server_database_create_(array $connection_config_): int
    {
        $database_name_ = trim((string) ($connection_config_['database'] ?? ''));

        if ($database_name_ === '') {
            $this->components->error('DB_DATABASE must be set.');

            return self::FAILURE;
        }

        $admin_connection_config_ = $this->server_database_admin_connection_config_build_($connection_config_);

        if ($this->server_database_exists_($admin_connection_config_, $database_name_)) {
            $this->components->warn('Database already exists: '.$database_name_);

            return self::FAILURE;
        }

        if (! $this->database_creation_should_continue_($database_name_)) {
            return self::FAILURE;
        }

        $this->database_create_with_schema_builder_($admin_connection_config_, $database_name_);

        $this->components->info('Database created: '.$database_name_);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    private function database_create_with_schema_builder_(array $connection_config_, string $database_name_): void
    {
        config([
            'database.connections.'.self::TEMP_CONNECTION_NAME => $connection_config_,
        ]);

        DB::purge(self::TEMP_CONNECTION_NAME);

        try {
            Schema::connection(self::TEMP_CONNECTION_NAME)->createDatabase($database_name_);
        } finally {
            DB::purge(self::TEMP_CONNECTION_NAME);
        }
    }

    private function database_creation_should_continue_(string $database_name_): bool
    {
        if ((string) $this->option('force') === '1') {
            return true;
        }

        if (! $this->confirm('Do you want to create database ['.$database_name_.']?', false)) {
            $this->components->info('Database creation cancelled.');

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     * @return array<string, mixed>
     */
    private function server_database_admin_connection_config_build_(array $connection_config_): array
    {
        $database_driver_ = (string) ($connection_config_['driver'] ?? '');

        return match ($database_driver_) {
            'mysql', 'mariadb' => [
                ...$connection_config_,
                'database' => null,
            ],
            'pgsql' => [
                ...$connection_config_,
                'database' => $connection_config_['admin_database'] ?? 'postgres',
            ],
            'sqlsrv' => [
                ...$connection_config_,
                'database' => $connection_config_['admin_database'] ?? 'master',
            ],
            default => $connection_config_,
        };
    }

    private function sqlite_database_path_resolve_(string $database_path_input_): string
    {
        if ($this->path_is_absolute_($database_path_input_)) {
            return $database_path_input_;
        }

        return base_path($database_path_input_);
    }

    private function sqlite_database_exists_(string $database_path_): bool
    {
        return File::exists($database_path_);
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    private function server_database_exists_(array $connection_config_, string $database_name_): bool
    {
        config([
            'database.connections.'.self::TEMP_CONNECTION_NAME => $connection_config_,
        ]);

        DB::purge(self::TEMP_CONNECTION_NAME);

        try {
            $schemas_ = Schema::connection(self::TEMP_CONNECTION_NAME)->getSchemas();

            foreach ($schemas_ as $schema_) {
                if (! is_array($schema_)) {
                    continue;
                }

                if (strtolower((string) ($schema_['name'] ?? '')) === strtolower($database_name_)) {
                    return true;
                }
            }

            return false;
        } finally {
            DB::purge(self::TEMP_CONNECTION_NAME);
        }
    }

    private function path_is_absolute_(string $path_input_): bool
    {
        if ($path_input_ === '') {
            return false;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path_input_) === 1) {
            return true;
        }

        return str_starts_with($path_input_, '/')
            || str_starts_with($path_input_, '\\');
    }
}
