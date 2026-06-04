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
    public function generate(
        string $connection_,
        string $name_argument_,
        string $database_name_ = '',
        bool $is_force_ = false,
        ?callable $on_output_ = null,
    ): array {
        $name_class_ = $this->normalizeSegmentKeepCase($name_argument_, 'default');
        $name_namespace_ = $this->normalizeLowerSegment($name_argument_, 'default');

        $schema_builder_ = Schema::connection($connection_);
        $table_names_ = $database_name_ === ''
            ? $schema_builder_->getTableListing(null, false)
            : $schema_builder_->getTableListing($database_name_, false);
        sort($table_names_);

        $db_root_path_ = app_path('Enums/db');
        $table_root_path_ = $db_root_path_.'/'.$name_namespace_;

        File::ensureDirectoryExists($db_root_path_);
        File::ensureDirectoryExists($table_root_path_);

        $written_files_count_ = 0;
        $skipped_files_count_ = 0;

        $db_enum_path_ = $db_root_path_.'/'.$name_class_.'.php';
        $db_enum_content_ = $this->buildDbTablesEnumContent($name_class_, $table_names_);

        if ($this->writeEnumFile($db_enum_path_, $db_enum_content_, $is_force_, $on_output_)) {
            $written_files_count_++;
        } else {
            $skipped_files_count_++;
        }

        foreach ($table_names_ as $table_name_) {
            $schema_table_name_ = $database_name_ === '' ? $table_name_ : $database_name_.'.'.$table_name_;
            $column_names_ = $schema_builder_->getColumnListing($schema_table_name_);
            $table_class_name_ = $this->normalizeSegmentKeepCase($table_name_, 'table');
            $table_enum_path_ = $table_root_path_.'/'.$table_class_name_.'.php';
            $table_enum_content_ = $this->buildTableColumnsEnumContent(
                $name_namespace_,
                $table_name_,
                $table_class_name_,
                $column_names_,
            );

            if ($this->writeEnumFile($table_enum_path_, $table_enum_content_, $is_force_, $on_output_)) {
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
    private function buildDbTablesEnumContent(string $class_name_, array $table_names_): string
    {
        $cases_ = [];

        foreach ($table_names_ as $table_name_) {
            $case_name_ = $this->toEnumCaseName($table_name_, 'TABLE');
            $cases_[] = "    case {$case_name_} = '{$table_name_}';";
        }

        $cases_text_ = $cases_ === []
            ? '    // 無資料表時保留空 enum，待下次執行自動補齊。'
            : implode(PHP_EOL, $cases_);

        return <<<PHP
<?php

namespace App\Enums\db;

// 此檔案由 db:hahaha_command_db_table_enum_generate 自動產生，請勿手動修改。
enum {$class_name_}: string
{
{$cases_text_}
}
PHP;
    }

    /**
     * @param array<int, string> $column_names_
     */
    private function buildTableColumnsEnumContent(
        string $name_namespace_,
        string $table_name_,
        string $class_name_,
        array $column_names_,
    ): string {
        $cases_ = [];

        foreach ($column_names_ as $column_name_) {
            $case_name_ = $this->toEnumCaseName($column_name_, 'COLUMN');
            $cases_[] = "    case {$case_name_} = '{$column_name_}';";
        }

        $cases_text_ = $cases_ === []
            ? "    // {$table_name_} 無欄位時保留空 enum。"
            : implode(PHP_EOL, $cases_);

        $table_namespace_ = 'App\\Enums\\db\\'.$name_namespace_;

        return <<<PHP
<?php

namespace {$table_namespace_};

// 此檔案由 db:hahaha_command_db_table_enum_generate 自動產生，請勿手動修改。
// 對應資料表：{$table_name_}
enum {$class_name_}: string
{
{$cases_text_}
}
PHP;
    }

    /**
     * @param callable(string): void|null $on_output_
     */
    private function writeEnumFile(
        string $path_,
        string $content_,
        bool $is_force_,
        ?callable $on_output_ = null,
    ): bool {
        if (File::exists($path_) && ! $is_force_) {
            $on_output_ && $on_output_("略過既有檔案：{$path_}（可加 --force 覆蓋）");

            return false;
        }

        File::ensureDirectoryExists(dirname($path_));
        File::put($path_, $content_.PHP_EOL);
        $on_output_ && $on_output_("已輸出：{$path_}");

        return true;
    }

    private function toEnumCaseName(string $name_, string $fallback_prefix_): string
    {
        $case_name_ = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name_));
        $case_name_ = trim($case_name_, '_');

        if ($case_name_ === '') {
            return $fallback_prefix_;
        }

        if (preg_match('/^[0-9]/', $case_name_) === 1) {
            return $fallback_prefix_.'_'.$case_name_;
        }

        return $case_name_;
    }

    private function normalizeSegmentKeepCase(string $value_, string $fallback_): string
    {
        $segment_ = (string) preg_replace('/[^A-Za-z0-9]+/', '_', $value_);
        $segment_ = trim($segment_, '_');

        return $segment_ === '' ? $fallback_ : $segment_;
    }

    private function normalizeLowerSegment(string $value_, string $fallback_): string
    {
        $segment_ = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $value_));
        $segment_ = trim($segment_, '_');

        return $segment_ === '' ? $fallback_ : $segment_;
    }
}
