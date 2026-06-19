<?php

namespace L_Lib\Console\Commands\agents\skills\outlook;

use InvalidArgumentException;
use L_Lib\Console\Commands\agents\skills\common\hahaha_command_agents_skills_base;
use L_Lib\Services\agents\skills\outlook\hahaha_service_agents_skills_outlook;

/**
 * 提供 Outlook 常用 inbox、message、draft、image 類 artisan 操作入口。
 */
class hahaha_command_agents_skills_outlook extends hahaha_command_agents_skills_base
{
    public $signature = 'l_lib:agents:skills:outlook
        {--action= : mail_folders_get|messages_get|message_get|message_update|draft_create|message_send|message_image_add|inbox_dashboard_get}
        {--user-id=me : Outlook user id}
        {--message-id= : Outlook message id}
        {--query= : JSON query parameters}
        {--payload= : JSON payload}
        {--file-path= : Image file path}
        {--file-name= : Image file name override}
        {--content-type=image/jpeg : Image content type}';

    public $description = 'Run Outlook common read/write actions through the Laravel service layer';

    public function __construct(public hahaha_service_agents_skills_outlook $service_)
    {
        parent::__construct();
    }

    /**
     * 依 action 分派 Outlook service 常用能力。
     */
    public function handle(): int
    {
        try {
            $action_ = $this->Required_Option_Resolve('action');
            $query_parameters_ = $this->Json_Option_Resolve('query');
            $payload_ = $this->Json_Option_Resolve('payload');
            $user_id_ = $this->Optional_Option_Resolve('user-id') ?: 'me';

            $result_ = match ($action_) {
                'mail_folders_get' => $this->service_->Mail_Folders_Get($user_id_, $query_parameters_),
                'messages_get' => $this->service_->Messages_Get($user_id_, $query_parameters_),
                'message_get' => $this->service_->Message_Get($this->Required_Option_Resolve('message-id'), $user_id_, $query_parameters_),
                'message_update' => $this->service_->Message_Update($this->Required_Option_Resolve('message-id'), $payload_, $user_id_),
                'draft_create' => $this->service_->Draft_Create($payload_, $user_id_),
                'message_send' => $this->service_->Message_Send($payload_, $user_id_),
                'message_image_add' => $this->service_->Message_Image_Add(
                    $this->Required_Option_Resolve('message-id'),
                    $this->File_Name_Resolve(),
                    $this->File_Content_Resolve('file-path'),
                    $this->Optional_Option_Resolve('content-type'),
                    $payload_,
                    $user_id_,
                ),
                'inbox_dashboard_get' => $this->service_->Inbox_Dashboard_Get($user_id_, $query_parameters_, $query_parameters_),
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
