<?php

namespace L_Lib\Services\db;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class hahaha_service_db_table_enum_generate
{
    /**
     * @param callable(string): void|null $on_output_
     * @return array{written_files_count: int, skipped_files_count: int}
     */
    public function Table_Enum_Generate(
        string $connection,
        string $name_argument,
        string $database_name = '',
        bool $is_force = false,
        ?callable $on_output = null,
    ): array {
        $name_class_ = $this->Normalize_Segment_Keep_Case($name_argument, 'default');
        $name_namespace_ = $this->Normalize_Lower_Segment($name_argument, 'default');

        $schema_builder_ = Schema::connection($connection);
        $table_names_ = $database_name === ''
            ? $schema_builder_->getTableListing(null, false)
            : $schema_builder_->getTableListing($database_name, false);
        sort($table_names_);

        $db_root_path_ = app_path('Enums/db');
        $table_root_path_ = $db_root_path_.'/'.$name_namespace_;

        File::ensureDirectoryExists($db_root_path_);
        File::ensureDirectoryExists($table_root_path_);

        $written_files_count_ = 0;
        $skipped_files_count_ = 0;

        $db_enum_path_ = $db_root_path_.'/'.$name_class_.'.php';
        $db_enum_content_ = $this->Build_Db_Tables_Enum_Content($name_class_, $table_names_);

        if ($this->Write_Enum_File($db_enum_path_, $db_enum_content_, $is_force, $on_output)) {
            $written_files_count_++;
        } else {
            $skipped_files_count_++;
        }

        foreach ($table_names_ as $table_name_) {
            $schema_table_name_ = $database_name === '' ? $table_name_ : $database_name.'.'.$table_name_;
            $column_names_ = $schema_builder_->getColumnListing($schema_table_name_);
            $table_class_name_ = $this->Normalize_Segment_Keep_Case($table_name_, 'table');
            $table_enum_path_ = $table_root_path_.'/'.$table_class_name_.'.php';
            $table_enum_content_ = $this->Build_Table_Columns_Enum_Content(
                $name_namespace_,
                $table_name_,
                $table_class_name_,
                $column_names_,
            );

            if ($this->Write_Enum_File($table_enum_path_, $table_enum_content_, $is_force, $on_output)) {
                $written_files_count_++;
            } else {
                $skipped_files_count_++;
            }
        }

        return [
            'written_files_count' => $written_files_count_,
            'skipped_files_count' => $skipped_files_count_,
        ];
    }

    /**
     * @param array<int, string> $table_names_
     */
    public function Build_Db_Tables_Enum_Content(string $class_name, array $table_names): string
    {
        $cases_ = [];

        foreach ($table_names as $table_name_) {
            $case_name_ = $this->To_Enum_Case_Name($table_name_, 'TABLE');
            $cases_[] = "    case {$case_name_} = '{$table_name_}';";
        }

        $cases_text_ = $cases_ === []
            ? '    // 無資料表時保留空 enum，待下次執行自動補齊。'
            : implode(PHP_EOL, $cases_);

        return <<<PHP
<?php

namespace App\Enums\db;

// 此檔案由 db:hahaha_command_db_table_enum_generate 自動產生，請勿手動修改。
enum {$class_name}: string
{
{$cases_text_}
}
PHP;
    }

    /**
     * @param array<int, string> $column_names_
     */
    public function Build_Table_Columns_Enum_Content(
        string $name_namespace,
        string $table_name,
        string $class_name,
        array $column_names,
    ): string {
        $cases_ = [];

        foreach ($column_names as $column_name_) {
            $case_name_ = $this->To_Enum_Case_Name($column_name_, 'COLUMN');
            $cases_[] = "    case {$case_name_} = '{$column_name_}';";
        }

        $cases_text_ = $cases_ === []
            ? "    // {$table_name} 無欄位時保留空 enum。"
            : implode(PHP_EOL, $cases_);

        $table_namespace_ = 'App\\Enums\\db\\'.$name_namespace;

        return <<<PHP
<?php

namespace {$table_namespace_};

// 此檔案由 db:hahaha_command_db_table_enum_generate 自動產生，請勿手動修改。
// 對應資料表：{$table_name}
enum {$class_name}: string
{
{$cases_text_}
}
PHP;
    }

    /**
     * @param callable(string): void|null $on_output_
     */
    public function Write_Enum_File(
        string $path,
        string $content,
        bool $is_force,
        ?callable $on_output = null,
    ): bool {
        if (File::exists($path) && ! $is_force) {
            $on_output && $on_output("略過既有檔案：{$path}（可加 --force 覆蓋）");

            return false;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content.PHP_EOL);
        $on_output && $on_output("已輸出：{$path}");

        return true;
    }

    public function To_Enum_Case_Name(string $name, string $fallback_prefix): string
    {
        $case_name_ = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name));
        $case_name_ = trim($case_name_, '_');

        if ($case_name_ === '') {
            return $fallback_prefix;
        }

        if (preg_match('/^[0-9]/', $case_name_) === 1) {
            return $fallback_prefix.'_'.$case_name_;
        }

        return $case_name_;
    }

    public function Normalize_Segment_Keep_Case(string $value, string $fallback): string
    {
        $segment_ = (string) preg_replace('/[^A-Za-z0-9]+/', '_', $value);
        $segment_ = trim($segment_, '_');

        return $segment_ === '' ? $fallback : $segment_;
    }

    public function Normalize_Lower_Segment(string $value, string $fallback): string
    {
        $segment_ = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $value));
        $segment_ = trim($segment_, '_');

        return $segment_ === '' ? $fallback : $segment_;
    }
}
