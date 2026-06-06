<?php

namespace L_Lib\Console\Commands\db;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class hahaha_command_rename_database extends Command
{
    private const TEMP_CONNECTION_NAME = 'hahaha_install_database_rename_';

    protected $signature = 'l_lib:db:rename_database
        {--from_database= : The source database name}
        {--to_database= : The target database name}
        {--connection= : The database connection name to use}
        {--force=2 : 1 forces rename, 2 requires confirmation before renaming}';

    protected $description = 'Rename the configured database for the selected Laravel database connection';

    public function handle(): int
    {
        $source_database_option_ = trim((string) $this->option('from_database'));
        $target_database_name_ = trim((string) $this->option('to_database'));
        $connection_name_option_ = trim((string) $this->option('connection'));
        $force_option_ = trim((string) $this->option('force'));

        if ($target_database_name_ === '') {
            $this->components->error('The --to_database option is required.');

            return self::FAILURE;
        }

        if (! in_array($force_option_, ['1', '2'], true)) {
            $this->components->error('The --force option must be 1 or 2.');

            return self::FAILURE;
        }

        $connection_name_ = $connection_name_option_ !== ''
            ? $connection_name_option_
            : (string) config('database.default', 'sqlite');
        $connection_config_ = config('database.connections.'.$connection_name_);

        if (! is_array($connection_config_)) {
            $this->components->error('Database connection is not configured: '.$connection_name_);

            return self::FAILURE;
        }

        $database_connection_ = (string) ($connection_config_['driver'] ?? '');
        $source_database_name_ = $source_database_option_ !== ''
            ? $source_database_option_
            : trim((string) ($connection_config_['database'] ?? ''));

        if ($source_database_name_ === '') {
            $this->components->error('The --from_database option is required when the connection database is not configured.');

            return self::FAILURE;
        }

        try {
            return match ($database_connection_) {
                'sqlite' => $this->sqlite_database_rename_($source_database_name_, $target_database_name_),
                'mysql', 'mariadb' => $this->mysql_database_rename_(
                    $database_connection_,
                    $source_database_name_,
                    $target_database_name_,
                    $connection_config_
                ),
                'pgsql', 'sqlsrv' => $this->server_database_rename_(
                    $database_connection_,
                    $source_database_name_,
                    $target_database_name_,
                    $connection_config_
                ),
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

    private function sqlite_database_rename_(
        string $source_database_name_,
        string $target_database_name_
    ): int {
        if ($source_database_name_ === ':memory:' || $target_database_name_ === ':memory:') {
            $this->components->error('SQLite in-memory databases cannot be renamed.');

            return self::FAILURE;
        }

        $source_database_path_ = $this->sqlite_database_path_resolve_($source_database_name_);
        $target_database_path_ = $this->sqlite_database_path_resolve_($target_database_name_);

        if (! File::exists($source_database_path_)) {
            $this->components->warn('Source database does not exist: '.$source_database_path_);

            return self::FAILURE;
        }

        if (File::exists($target_database_path_)) {
            $this->components->warn('Target database already exists: '.$target_database_path_);

            return self::FAILURE;
        }

        if (! $this->database_rename_should_continue_($source_database_name_, $target_database_name_)) {
            return self::FAILURE;
        }

        $target_database_directory_ = dirname($target_database_path_);

        if (! File::isDirectory($target_database_directory_)) {
            File::makeDirectory($target_database_directory_, 0755, true);
        }

        File::move($source_database_path_, $target_database_path_);

        $this->components->info('Database renamed: '.$source_database_name_.' -> '.$target_database_name_);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    private function mysql_database_rename_(
        string $database_connection_,
        string $source_database_name_,
        string $target_database_name_,
        array $connection_config_
    ): int {
        $admin_connection_config_ = $this->database_connection_config_build_($database_connection_, $connection_config_);

        if (! $this->server_database_exists_($admin_connection_config_, $source_database_name_)) {
            $this->components->warn('Source database does not exist: '.$source_database_name_);

            return self::FAILURE;
        }

        if ($this->server_database_exists_($admin_connection_config_, $target_database_name_)) {
            $this->components->warn('Target database already exists: '.$target_database_name_);

            return self::FAILURE;
        }

        if (! $this->database_rename_should_continue_($source_database_name_, $target_database_name_)) {
            return self::FAILURE;
        }

        config([
            'database.connections.'.self::TEMP_CONNECTION_NAME => $admin_connection_config_,
        ]);

        DB::purge(self::TEMP_CONNECTION_NAME);

        try {
            /** @var Connection $database_connection_instance_ */
            $database_connection_instance_ = DB::connection(self::TEMP_CONNECTION_NAME);
            $schema_builder_ = $database_connection_instance_->getSchemaBuilder();

            $schema_builder_->createDatabase($target_database_name_);

            $table_names_ = $schema_builder_->getTableListing($source_database_name_, false);

            foreach ($table_names_ as $table_name_) {
                $database_connection_instance_->unprepared(
                    $this->mysql_table_rename_statement_build_(
                        $source_database_name_,
                        $target_database_name_,
                        (string) $table_name_
                    )
                );
            }

            $schema_builder_->dropDatabaseIfExists($source_database_name_);
        } finally {
            DB::purge(self::TEMP_CONNECTION_NAME);
        }

        $this->components->info('Database renamed: '.$source_database_name_.' -> '.$target_database_name_);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    private function server_database_rename_(
        string $database_connection_,
        string $source_database_name_,
        string $target_database_name_,
        array $connection_config_
    ): int {
        $admin_connection_config_ = $this->database_connection_config_build_($database_connection_, $connection_config_);
        $database_rename_statement_ = $this->database_rename_statement_build_(
            $database_connection_,
            $source_database_name_,
            $target_database_name_
        );

        if (! $this->server_database_exists_($admin_connection_config_, $source_database_name_)) {
            $this->components->warn('Source database does not exist: '.$source_database_name_);

            return self::FAILURE;
        }

        if ($this->server_database_exists_($admin_connection_config_, $target_database_name_)) {
            $this->components->warn('Target database already exists: '.$target_database_name_);

            return self::FAILURE;
        }

        if (! $this->database_rename_should_continue_($source_database_name_, $target_database_name_)) {
            return self::FAILURE;
        }

        config([
            'database.connections.'.self::TEMP_CONNECTION_NAME => $admin_connection_config_,
        ]);

        DB::purge(self::TEMP_CONNECTION_NAME);

        try {
            /** @var Connection $database_connection_instance_ */
            $database_connection_instance_ = DB::connection(self::TEMP_CONNECTION_NAME);

            if ($this->database_rename_with_schema_builder_if_supported_(
                $database_connection_instance_,
                $source_database_name_,
                $target_database_name_
            )) {
                $this->components->info('Database renamed: '.$source_database_name_.' -> '.$target_database_name_);

                return self::SUCCESS;
            }

            $database_connection_instance_->unprepared($database_rename_statement_);
        } finally {
            DB::purge(self::TEMP_CONNECTION_NAME);
        }

        $this->components->info('Database renamed: '.$source_database_name_.' -> '.$target_database_name_);

        return self::SUCCESS;
    }

    private function database_rename_should_continue_(string $source_database_name_, string $target_database_name_): bool
    {
        if ((string) $this->option('force') === '1') {
            return true;
        }

        if (! $this->confirm('Do you want to rename database ['.$source_database_name_.'] to ['.$target_database_name_.']?', false)) {
            $this->components->info('Database rename cancelled.');

            return false;
        }

        return true;
    }

    private function database_rename_with_schema_builder_if_supported_(
        Connection $database_connection_,
        string $source_database_name_,
        string $target_database_name_
    ): bool {
        $schema_builder_ = $database_connection_->getSchemaBuilder();

        if (! method_exists($schema_builder_, 'renameDatabase')) {
            return false;
        }

        $schema_builder_->renameDatabase($source_database_name_, $target_database_name_);

        return true;
    }

    private function mysql_table_rename_statement_build_(
        string $source_database_name_,
        string $target_database_name_,
        string $table_name_
    ): string {
        return 'RENAME TABLE '
            .$this->mysql_database_table_identifier_escape_($source_database_name_, $table_name_)
            .' TO '
            .$this->mysql_database_table_identifier_escape_($target_database_name_, $table_name_);
    }

    private function mysql_database_table_identifier_escape_(string $database_name_, string $table_name_): string
    {
        return '`'.str_replace('`', '``', $database_name_).'`.'
            .'`'.str_replace('`', '``', $table_name_).'`';
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
            default => $connection_config_,
        };
    }

    private function database_rename_statement_build_(
        string $database_connection_,
        string $source_database_name_,
        string $target_database_name_
    ): string {
        $escaped_source_database_name_ = $this->database_identifier_escape_($database_connection_, $source_database_name_);
        $escaped_target_database_name_ = $this->database_identifier_escape_($database_connection_, $target_database_name_);

        return match ($database_connection_) {
            'pgsql' => 'ALTER DATABASE '.$escaped_source_database_name_.' RENAME TO '.$escaped_target_database_name_,
            'sqlsrv' => 'ALTER DATABASE '.$escaped_source_database_name_.' MODIFY NAME = '.$escaped_target_database_name_,
            default => '',
        };
    }

    private function database_identifier_escape_(string $database_connection_, string $database_name_): string
    {
        return match ($database_connection_) {
            'pgsql' => '"'.str_replace('"', '""', $database_name_).'"',
            'sqlsrv' => '['.str_replace(']', ']]', $database_name_).']',
            default => $database_name_,
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
