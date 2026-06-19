<?php

namespace L_Lib\Console\Commands\agents\skills\trello;

use InvalidArgumentException;
use L_Lib\Console\Commands\agents\skills\common\hahaha_command_agents_skills_base;
use L_Lib\Services\agents\skills\trello\hahaha_service_agents_skills_trello;

/**
 * 提供 Trello 常用 board、card、dashboard、image 類 artisan 操作入口。
 */
class hahaha_command_agents_skills_trello extends hahaha_command_agents_skills_base
{
    public $signature = 'l_lib:agents:skills:trello
        {--action= : board_get|board_dashboard_get|card_get|card_create|card_update|card_move|card_comment_add|card_image_add|card_archive}
        {--board-id= : Trello board id}
        {--card-id= : Trello card id}
        {--list-id= : Trello list id}
        {--comment= : Comment text}
        {--query= : JSON query parameters}
        {--payload= : JSON payload}
        {--file-path= : Image file path}
        {--file-name= : Image file name override}
        {--content-type=image/jpeg : Image content type}';

    public $description = 'Run Trello common read/write actions through the Laravel service layer';

    public function __construct(public hahaha_service_agents_skills_trello $service_)
    {
        parent::__construct();
    }

    /**
     * 依 action 分派 Trello service 常用能力。
     */
    public function handle(): int
    {
        try {
            $action_ = $this->Required_Option_Resolve('action');
            $query_parameters_ = $this->Json_Option_Resolve('query');
            $payload_ = $this->Json_Option_Resolve('payload');

            $result_ = match ($action_) {
                'board_get' => $this->service_->Board_Get($this->Required_Option_Resolve('board-id'), $query_parameters_),
                'board_dashboard_get' => $this->service_->Board_Dashboard_Get($this->Required_Option_Resolve('board-id'), $query_parameters_, $query_parameters_, $query_parameters_),
                'card_get' => $this->service_->Card_Get($this->Required_Option_Resolve('card-id'), $query_parameters_),
                'card_create' => $this->service_->Card_Create($this->Required_Option_Resolve('list-id'), $payload_),
                'card_update' => $this->service_->Card_Update($this->Required_Option_Resolve('card-id'), $payload_),
                'card_move' => $this->service_->Card_Move($this->Required_Option_Resolve('card-id'), $this->Required_Option_Resolve('list-id'), $payload_),
                'card_comment_add' => $this->service_->Card_Comment_Add($this->Required_Option_Resolve('card-id'), $this->Required_Option_Resolve('comment')),
                'card_image_add' => $this->service_->Card_Image_Add(
                    $this->Required_Option_Resolve('card-id'),
                    $this->File_Name_Resolve(),
                    $this->File_Content_Resolve('file-path'),
                    $this->Optional_Option_Resolve('content-type'),
                    $payload_,
                ),
                'card_archive' => $this->service_->Card_Archive($this->Required_Option_Resolve('card-id')),
                default => throw new InvalidArgumentException('Unsupported action: '.$action_),
            };
        } catch (InvalidArgumentException $exception_) {
            $this->components->error($exception_->getMessage());

            return self::FAILURE;
        }

        $this->Result_Output($result_);

        return self::SUCCESS;
    }

    /**
     * 解析上傳圖片用的檔名，未指定時自動取自 file path。
     */
    public function File_Name_Resolve(): string
    {
        $file_name_ = $this->Optional_Option_Resolve('file-name');

        if ($file_name_ !== '') {
            return $file_name_;
        }

        return basename($this->Required_Option_Resolve('file-path'));
    }
}
