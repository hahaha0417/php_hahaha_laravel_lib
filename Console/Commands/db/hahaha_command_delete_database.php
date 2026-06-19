<?php

namespace L_Lib\Console\Commands\db;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class hahaha_command_delete_database extends Command
{
    public const TEMP_CONNECTION_NAME = 'hahaha_install_database_delete_';

    public $signature = 'l_lib:db:delete_database
        {--database= : The database name to delete}
        {--connection= : The database connection name to use}
        {--force=2 : 1 forces deletion, 2 requires confirmation before deletion}';

    public $description = 'Delete the configured database using the current Laravel database configuration';

    public function handle(): int
    {
        $database_option_ = trim((string) $this->option('database'));
        $connection_option_ = trim((string) $this->option('connection'));
        $force_option_ = trim((string) $this->option('force'));
        $connection_name_ = $connection_option_ !== ''
            ? $connection_option_
            : (string) config('database.default', 'sqlite');
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

        $database_driver_ = (string) ($connection_config_['driver'] ?? '');

        try {
            return match ($database_driver_) {
                'sqlite' => $this->Sqlite_Database_Delete($connection_config_),
                'mysql', 'mariadb', 'pgsql', 'sqlsrv' => $this->Server_Database_Delete($database_driver_, $connection_config_),
                default => $this->Database_Connection_Unsupported($database_driver_),
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
    public function Sqlite_Database_Delete(array $connection_config): int
    {
        $database_path_input_ = trim((string) ($connection_config['database'] ?? ''));

        if ($database_path_input_ === '') {
            $this->components->error('DB_DATABASE must be set for sqlite connections.');

            return self::FAILURE;
        }

        if ($database_path_input_ === ':memory:') {
            $this->components->info('SQLite in-memory database does not need to be deleted.');

            return self::SUCCESS;
        }

        $database_path_ = $this->Sqlite_Database_Path_Resolve($database_path_input_);

        if (! File::exists($database_path_)) {
            $this->components->warn('Database does not exist: '.$database_path_);

            return self::FAILURE;
        }

        if (! $this->Database_Deletion_Should_Continue($database_path_)) {
            return self::FAILURE;
        }

        File::delete($database_path_);

        $this->components->info('Database deleted at '.$database_path_);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    public function Server_Database_Delete(string $database_connection, array $connection_config): int
    {
        $database_name_ = trim((string) ($connection_config['database'] ?? ''));

        if ($database_name_ === '') {
            $this->components->error('DB_DATABASE must be set.');

            return self::FAILURE;
        }

        $database_exists_ = $this->Server_Database_Exists(
            $this->Database_Connection_Config_Build($database_connection, $connection_config),
            $database_name_
        );

        if (! $database_exists_) {
            $this->components->warn('Database does not exist: '.$database_name_);

            return self::FAILURE;
        }

        if (! $this->Database_Deletion_Should_Continue($database_name_)) {
            return self::FAILURE;
        }

        $this->Database_Delete_With_Schema_Builder(
            $this->Database_Connection_Config_Build($database_connection, $connection_config),
            $database_name_
        );

        $this->components->info('Database deleted: '.$database_name_);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    public function Database_Delete_With_Schema_Builder(array $connection_config, string $database_name): void
    {
        config([
            'database.connections.'.self::TEMP_CONNECTION_NAME => $connection_config,
        ]);

        DB::purge(self::TEMP_CONNECTION_NAME);

        try {
            /** @var Connection $database_connection_ */
            $database_connection_ = DB::connection(self::TEMP_CONNECTION_NAME);

            $database_connection_->getSchemaBuilder()->dropDatabaseIfExists($database_name);
        } finally {
            DB::purge(self::TEMP_CONNECTION_NAME);
        }
    }

    public function Database_Deletion_Should_Continue(string $database_name): bool
    {
        if ((string) $this->option('force') === '1') {
            return true;
        }

        if (! $this->confirm('Do you want to delete database ['.$database_name.']?', false)) {
            $this->components->info('Database deletion cancelled.');

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     * @return array<string, mixed>
     */
    public function Database_Connection_Config_Build(string $database_connection, array $connection_config): array
    {
        return match ($database_connection) {
            'mysql', 'mariadb' => [
                ...Arr::except($connection_config, ['database']),
                'driver' => $database_connection,
                'database' => null,
            ],
            'pgsql' => [
                ...$connection_config,
                'driver' => 'pgsql',
                'database' => $connection_config['admin_database'] ?? 'postgres',
            ],
            'sqlsrv' => [
                ...$connection_config,
                'driver' => 'sqlsrv',
                'database' => $connection_config['admin_database'] ?? 'master',
            ],
            default => [],
        };
    }

    public function Sqlite_Database_Path_Resolve(string $database_path_input): string
    {
        if ($this->Path_Is_Absolute($database_path_input)) {
            return $database_path_input;
        }

        return base_path($database_path_input);
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
            /** @var Connection $database_connection_ */
            $database_connection_ = DB::connection(self::TEMP_CONNECTION_NAME);
            $schemas_ = $database_connection_->getSchemaBuilder()->getSchemas();

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
