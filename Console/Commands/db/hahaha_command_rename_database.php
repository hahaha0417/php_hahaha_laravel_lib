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
    public const TEMP_CONNECTION_NAME = 'hahaha_install_database_rename_';

    public $signature = 'l_lib:db:rename_database
        {--from_database= : The source database name}
        {--to_database= : The target database name}
        {--connection= : The database connection name to use}
        {--force=2 : 1 forces rename, 2 requires confirmation before renaming}';

    public $description = 'Rename the configured database for the selected Laravel database connection';

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
                'sqlite' => $this->Sqlite_Database_Rename($source_database_name_, $target_database_name_),
                'mysql', 'mariadb' => $this->Mysql_Database_Rename(
                    $database_connection_,
                    $source_database_name_,
                    $target_database_name_,
                    $connection_config_
                ),
                'pgsql', 'sqlsrv' => $this->Server_Database_Rename(
                    $database_connection_,
                    $source_database_name_,
                    $target_database_name_,
                    $connection_config_
                ),
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

    public function Sqlite_Database_Rename(
        string $source_database_name,
        string $target_database_name
    ): int {
        if ($source_database_name === ':memory:' || $target_database_name === ':memory:') {
            $this->components->error('SQLite in-memory databases cannot be renamed.');

            return self::FAILURE;
        }

        $source_database_path_ = $this->Sqlite_Database_Path_Resolve($source_database_name);
        $target_database_path_ = $this->Sqlite_Database_Path_Resolve($target_database_name);

        if (! File::exists($source_database_path_)) {
            $this->components->warn('Source database does not exist: '.$source_database_path_);

            return self::FAILURE;
        }

        if (File::exists($target_database_path_)) {
            $this->components->warn('Target database already exists: '.$target_database_path_);

            return self::FAILURE;
        }

        if (! $this->Database_Rename_Should_Continue($source_database_name, $target_database_name)) {
            return self::FAILURE;
        }

        $target_database_directory_ = dirname($target_database_path_);

        if (! File::isDirectory($target_database_directory_)) {
            File::makeDirectory($target_database_directory_, 0755, true);
        }

        File::move($source_database_path_, $target_database_path_);

        $this->components->info('Database renamed: '.$source_database_name.' -> '.$target_database_name);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    public function Mysql_Database_Rename(
        string $database_connection,
        string $source_database_name,
        string $target_database_name,
        array $connection_config
    ): int {
        $admin_connection_config_ = $this->Database_Connection_Config_Build($database_connection, $connection_config);

        if (! $this->Server_Database_Exists($admin_connection_config_, $source_database_name)) {
            $this->components->warn('Source database does not exist: '.$source_database_name);

            return self::FAILURE;
        }

        if ($this->Server_Database_Exists($admin_connection_config_, $target_database_name)) {
            $this->components->warn('Target database already exists: '.$target_database_name);

            return self::FAILURE;
        }

        if (! $this->Database_Rename_Should_Continue($source_database_name, $target_database_name)) {
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

            $schema_builder_->createDatabase($target_database_name);

            $table_names_ = $schema_builder_->getTableListing($source_database_name, false);

            foreach ($table_names_ as $table_name_) {
                $database_connection_instance_->unprepared(
                    $this->Mysql_Table_Rename_Statement_Build(
                        $source_database_name,
                        $target_database_name,
                        (string) $table_name_
                    )
                );
            }

            $schema_builder_->dropDatabaseIfExists($source_database_name);
        } finally {
            DB::purge(self::TEMP_CONNECTION_NAME);
        }

        $this->components->info('Database renamed: '.$source_database_name.' -> '.$target_database_name);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection_config_
     */
    public function Server_Database_Rename(
        string $database_connection,
        string $source_database_name,
        string $target_database_name,
        array $connection_config
    ): int {
        $admin_connection_config_ = $this->Database_Connection_Config_Build($database_connection, $connection_config);
        $database_rename_statement_ = $this->Database_Rename_Statement_Build(
            $database_connection,
            $source_database_name,
            $target_database_name
        );

        if (! $this->Server_Database_Exists($admin_connection_config_, $source_database_name)) {
            $this->components->warn('Source database does not exist: '.$source_database_name);

            return self::FAILURE;
        }

        if ($this->Server_Database_Exists($admin_connection_config_, $target_database_name)) {
            $this->components->warn('Target database already exists: '.$target_database_name);

            return self::FAILURE;
        }

        if (! $this->Database_Rename_Should_Continue($source_database_name, $target_database_name)) {
            return self::FAILURE;
        }

        config([
            'database.connections.'.self::TEMP_CONNECTION_NAME => $admin_connection_config_,
        ]);

        DB::purge(self::TEMP_CONNECTION_NAME);

        try {
            /** @var Connection $database_connection_instance_ */
            $database_connection_instance_ = DB::connection(self::TEMP_CONNECTION_NAME);

            if ($this->Database_Rename_With_Schema_Builder_If_Supported(
                $database_connection_instance_,
                $source_database_name,
                $target_database_name
            )) {
                $this->components->info('Database renamed: '.$source_database_name.' -> '.$target_database_name);

                return self::SUCCESS;
            }

            $database_connection_instance_->unprepared($database_rename_statement_);
        } finally {
            DB::purge(self::TEMP_CONNECTION_NAME);
        }

        $this->components->info('Database renamed: '.$source_database_name.' -> '.$target_database_name);

        return self::SUCCESS;
    }

    public function Database_Rename_Should_Continue(string $source_database_name, string $target_database_name): bool
    {
        if ((string) $this->option('force') === '1') {
            return true;
        }

        if (! $this->confirm('Do you want to rename database ['.$source_database_name.'] to ['.$target_database_name.']?', false)) {
            $this->components->info('Database rename cancelled.');

            return false;
        }

        return true;
    }

    public function Database_Rename_With_Schema_Builder_If_Supported(
        Connection $database_connection,
        string $source_database_name,
        string $target_database_name
    ): bool {
        $schema_builder_ = $database_connection->getSchemaBuilder();

        if (! method_exists($schema_builder_, 'renameDatabase')) {
            return false;
        }

        $schema_builder_->renameDatabase($source_database_name, $target_database_name);

        return true;
    }

    public function Mysql_Table_Rename_Statement_Build(
        string $source_database_name,
        string $target_database_name,
        string $table_name
    ): string {
        return 'RENAME TABLE '
            .$this->Mysql_Database_Table_Identifier_Escape($source_database_name, $table_name)
            .' TO '
            .$this->Mysql_Database_Table_Identifier_Escape($target_database_name, $table_name);
    }

    public function Mysql_Database_Table_Identifier_Escape(string $database_name, string $table_name): string
    {
        return '`'.str_replace('`', '``', $database_name).'`.'
            .'`'.str_replace('`', '``', $table_name).'`';
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
            default => $connection_config,
        };
    }

    public function Database_Rename_Statement_Build(
        string $database_connection,
        string $source_database_name,
        string $target_database_name
    ): string {
        $escaped_source_database_name_ = $this->Database_Identifier_Escape($database_connection, $source_database_name);
        $escaped_target_database_name_ = $this->Database_Identifier_Escape($database_connection, $target_database_name);

        return match ($database_connection) {
            'pgsql' => 'ALTER DATABASE '.$escaped_source_database_name_.' RENAME TO '.$escaped_target_database_name_,
            'sqlsrv' => 'ALTER DATABASE '.$escaped_source_database_name_.' MODIFY NAME = '.$escaped_target_database_name_,
            default => '',
        };
    }

    public function Database_Identifier_Escape(string $database_connection, string $database_name): string
    {
        return match ($database_connection) {
            'pgsql' => '"'.str_replace('"', '""', $database_name).'"',
            'sqlsrv' => '['.str_replace(']', ']]', $database_name).']',
            default => $database_name,
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
