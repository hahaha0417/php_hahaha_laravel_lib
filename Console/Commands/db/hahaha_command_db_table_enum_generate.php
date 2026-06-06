<?php

namespace L_Lib\Console\Commands\db;

use L_Lib\Services\db\hahaha_service_db_table_enum_generate;
use Illuminate\Console\Command;

class hahaha_command_db_table_enum_generate extends Command
{
    protected $signature = 'l_lib:db:hahaha_command_db_table_enum_generate
        {--connection=mysql : 指定資料庫連線名稱}
        {--name=hahaha : 指定輸出名稱（namespace / 檔名）}
        {--database=codex : 指定資料庫名稱（schema）}
        {--force : 覆蓋既有檔案}';

    protected $description = '產生指定資料庫的資料表 enum 與資料表欄位 enum（PSR-4 結構）';

    public function __construct(
        private readonly hahaha_service_db_table_enum_generate $hahaha_service_db_table_enum_generate_,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection_ = trim((string) $this->option('connection'));
        $name_argument_ = trim((string) $this->option('name'));
        $database_name_ = trim((string) ($this->option('database') ?? ''));
        $is_force_ = (bool) $this->option('force');

        if ($connection_ === '') {
            $this->components->error('connection 不可為空。');

            return self::FAILURE;
        }

        if ($name_argument_ === '') {
            $this->components->error('name 不可為空。');

            return self::FAILURE;
        }

        $result_ = $this->hahaha_service_db_table_enum_generate_->generate(
            connection_: $connection_,
            name_argument_: $name_argument_,
            database_name_: $database_name_,
            is_force_: $is_force_,
            on_output_: fn (string $message_) => $this->components->info($message_),
        );

        $this->components->info(
            sprintf(
                '完成，共產生 %d 個 enum 檔案，略過 %d 個既有檔案。',
                $result_['written_files_count'],
                $result_['skipped_files_count'],
            ),
        );

        return self::SUCCESS;
    }
}
