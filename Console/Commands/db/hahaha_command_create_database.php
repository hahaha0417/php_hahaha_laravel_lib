<?php

namespace L_Lib\Console\Commands\db;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class hahaha_command_create_database extends Command
{
    public const TEMP_CONNECTION_NAME = 'hahaha_install_database_create_';

    public $signature = 'l_lib:db:create_database
        {--database= : The database name to create}
        {--connection= : The database connection name to use}
        {--force=2 : 1 forces creation, 2 requires confirmation when the database already exists}';

    public $description = 'Create the configured database using the Laravel database configuration';

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
                'sqlite' => $this->Sqlite_Database_Create($connection_config_),
                'mysql', 'mariadb', 'pgsql', 'sqlsrv' => $this->Server_Database_Create($connection_config_),
                default => $this->Database_Connection_Unsupported($database_connection_),
            };
        } catch (Throwable $throwable_) {
            $this->components->error($throwable_->getMessage());

            return self::FAILURE;
        }
    }

    public function Database_Connection_Unsupported(string $database_connection): int
    {
        $this->components->error('Unsupported DB_CONNECTION value: '.$database_connection);

        return self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    public function Sqlite_Database_Create(array $connection_config): int
    {
        $database_path_input_ = (string) ($connection_config['database'] ?? '');

        if ($database_path_input_ === '') {
            $this->components->error('DB_DATABASE must be set for sqlite connections.');

            return self::FAILURE;
        }

        if ($database_path_input_ === ':memory:') {
            $this->components->info('SQLite in-memory database does not need to be created.');

            return self::SUCCESS;
        }

        $database_path_ = $this->Sqlite_Database_Path_Resolve($database_path_input_);
        $database_directory_ = dirname($database_path_);

        if (! File::isDirectory($database_directory_)) {
            File::makeDirectory($database_directory_, 0755, true);
        }

        $connection_config['database'] = $database_path_;

        if ($this->Sqlite_Database_Exists($database_path_)) {
            $this->components->warn('Database already exists: '.$database_path_);

            return self::FAILURE;
        }

        if (! $this->Database_Creation_Should_Continue($database_path_)) {
            return self::FAILURE;
        }

        $this->Database_Create_With_Schema_Builder($connection_config, $database_path_);

        $this->components->info('Database created at '.$database_path_);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    public function Server_Database_Create(array $connection_config): int
    {
        $database_name_ = trim((string) ($connection_config['database'] ?? ''));

        if ($database_name_ === '') {
            $this->components->error('DB_DATABASE must be set.');

            return self::FAILURE;
        }

        $admin_connection_config_ = $this->Server_Database_Admin_Connection_Config_Build($connection_config);

        if ($this->Server_Database_Exists($admin_connection_config_, $database_name_)) {
            $this->components->warn('Database already exists: '.$database_name_);

            return self::FAILURE;
        }

        if (! $this->Database_Creation_Should_Continue($database_name_)) {
            return self::FAILURE;
        }

        $this->Database_Create_With_Schema_Builder($admin_connection_config_, $database_name_);

        $this->components->info('Database created: '.$database_name_);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    public function Database_Create_With_Schema_Builder(array $connection_config, string $database_name): void
    {
        config([
            'database.connections.'.self::TEMP_CONNECTION_NAME => $connection_config,
        ]);

        DB::purge(self::TEMP_CONNECTION_NAME);

        try {
            Schema::connection(self::TEMP_CONNECTION_NAME)->createDatabase($database_name);
        } finally {
            DB::purge(self::TEMP_CONNECTION_NAME);
        }
    }

    public function Database_Creation_Should_Continue(string $database_name): bool
    {
        if ((string) $this->option('force') === '1') {
            return true;
        }

        if (! $this->confirm('Do you want to create database ['.$database_name.']?', false)) {
            $this->components->info('Database creation cancelled.');

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     * @return array<string, mixed>
     */
    public function Server_Database_Admin_Connection_Config_Build(array $connection_config): array
    {
        $database_driver_ = (string) ($connection_config['driver'] ?? '');

        return match ($database_driver_) {
            'mysql', 'mariadb' => [
                ...$connection_config,
                'database' => null,
            ],
            'pgsql' => [
                ...$connection_config,
                'database' => $connection_config['admin_database'] ?? 'postgres',
            ],
            'sqlsrv' => [
                ...$connection_config,
                'database' => $connection_config['admin_database'] ?? 'master',
            ],
            default => $connection_config,
        };
    }

    public function Sqlite_Database_Path_Resolve(string $database_path_input): string
    {
        if ($this->Path_Is_Absolute($database_path_input)) {
            return $database_path_input;
        }

        return base_path($database_path_input);
    }

    public function Sqlite_Database_Exists(string $database_path): bool
    {
        return File::exists($database_path);
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    public function Server_Database_Exists(array $connection_config, string $database_name): bool
    {
        config([
            'database.connections.'.self::TEMP_CONNECTION_NAME => $connection_config,
        ]);

        DB::purge(self::TEMP_CONNECTION_NAME);

        try {
            $schemas_ = Schema::connection(self::TEMP_CONNECTION_NAME)->getSchemas();

            foreach ($schemas_ as $schema_) {
                if (! is_array($schema_)) {
                    continue;
                }

                if (strtolower((string) ($schema_['name'] ?? '')) === strtolower($database_name)) {
                    return true;
                }
            }

            return false;
        } finally {
            DB::purge(self::TEMP_CONNECTION_NAME);
        }
    }

    public function Path_Is_Absolute(string $path_input): bool
    {
        if ($path_input === '') {
            return false;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path_input) === 1) {
            return true;
        }

        return str_starts_with($path_input, '/')
            || str_starts_with($path_input, '\\');
    }
}
