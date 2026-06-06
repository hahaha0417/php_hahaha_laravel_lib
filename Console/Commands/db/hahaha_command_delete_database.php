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
    private const TEMP_CONNECTION_NAME = 'hahaha_install_database_delete_';

    protected $signature = 'l_lib:db:delete_database
        {--database= : The database name to delete}
        {--connection= : The database connection name to use}
        {--force=2 : 1 forces deletion, 2 requires confirmation before deletion}';

    protected $description = 'Delete the configured database using the current Laravel database configuration';

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
                'sqlite' => $this->sqlite_database_delete_($connection_config_),
                'mysql', 'mariadb', 'pgsql', 'sqlsrv' => $this->server_database_delete_($database_driver_, $connection_config_),
                default => $this->database_connection_unsupported_($database_driver_),
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
    private function sqlite_database_delete_(array $connection_config_): int
    {
        $database_path_input_ = trim((string) ($connection_config_['database'] ?? ''));

        if ($database_path_input_ === '') {
            $this->components->error('DB_DATABASE must be set for sqlite connections.');

            return self::FAILURE;
        }

        if ($database_path_input_ === ':memory:') {
            $this->components->info('SQLite in-memory database does not need to be deleted.');

            return self::SUCCESS;
        }

        $database_path_ = $this->sqlite_database_path_resolve_($database_path_input_);

        if (! File::exists($database_path_)) {
            $this->components->warn('Database does not exist: '.$database_path_);

            return self::FAILURE;
        }

        if (! $this->database_deletion_should_continue_($database_path_)) {
            return self::FAILURE;
        }

        File::delete($database_path_);

        $this->components->info('Database deleted at '.$database_path_);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    private function server_database_delete_(string $database_connection_, array $connection_config_): int
    {
        $database_name_ = trim((string) ($connection_config_['database'] ?? ''));

        if ($database_name_ === '') {
            $this->components->error('DB_DATABASE must be set.');

            return self::FAILURE;
        }

        $database_exists_ = $this->server_database_exists_(
            $this->database_connection_config_build_($database_connection_, $connection_config_),
            $database_name_
        );

        if (! $database_exists_) {
            $this->components->warn('Database does not exist: '.$database_name_);

            return self::FAILURE;
        }

        if (! $this->database_deletion_should_continue_($database_name_)) {
            return self::FAILURE;
        }

        $this->database_delete_with_schema_builder_(
            $this->database_connection_config_build_($database_connection_, $connection_config_),
            $database_name_
        );

        $this->components->info('Database deleted: '.$database_name_);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    private function database_delete_with_schema_builder_(array $connection_config_, string $database_name_): void
    {
        config([
            'database.connections.'.self::TEMP_CONNECTION_NAME => $connection_config_,
        ]);

        DB::purge(self::TEMP_CONNECTION_NAME);

        try {
            /** @var Connection $database_connection_ */
            $database_connection_ = DB::connection(self::TEMP_CONNECTION_NAME);

            $database_connection_->getSchemaBuilder()->dropDatabaseIfExists($database_name_);
        } finally {
            DB::purge(self::TEMP_CONNECTION_NAME);
        }
    }

    private function database_deletion_should_continue_(string $database_name_): bool
    {
        if ((string) $this->option('force') === '1') {
            return true;
        }

        if (! $this->confirm('Do you want to delete database ['.$database_name_.']?', false)) {
            $this->components->info('Database deletion cancelled.');

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     * @return array<string, mixed>
     */
    private function database_connection_config_build_(string $database_connection_, array $connection_config_): array
    {
        return match ($database_connection_) {
            'mysql', 'mariadb' => [
                ...Arr::except($connection_config_, ['database']),
                'driver' => $database_connection_,
                'database' => null,
            ],
            'pgsql' => [
                ...$connection_config_,
                'driver' => 'pgsql',
                'database' => $connection_config_['admin_database'] ?? 'postgres',
            ],
            'sqlsrv' => [
                ...$connection_config_,
                'driver' => 'sqlsrv',
                'database' => $connection_config_['admin_database'] ?? 'master',
            ],
            default => [],
        };
    }

    private function sqlite_database_path_resolve_(string $database_path_input_): string
    {
        if ($this->path_is_absolute_($database_path_input_)) {
            return $database_path_input_;
        }

        return base_path($database_path_input_);
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
            /** @var Connection $database_connection_ */
            $database_connection_ = DB::connection(self::TEMP_CONNECTION_NAME);
            $schemas_ = $database_connection_->getSchemaBuilder()->getSchemas();

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
