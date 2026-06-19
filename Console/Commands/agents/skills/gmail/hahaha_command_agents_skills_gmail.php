<?php

namespace L_Lib\Console\Commands\agents\skills\gmail;

use InvalidArgumentException;
use L_Lib\Console\Commands\agents\skills\common\hahaha_command_agents_skills_base;
use L_Lib\Services\agents\skills\gmail\hahaha_service_agents_skills_gmail;

/**
 * 提供 Gmail 常用 inbox、thread、draft、image mail 類 artisan 操作入口。
 */
class hahaha_command_agents_skills_gmail extends hahaha_command_agents_skills_base
{
    public $signature = 'l_lib:agents:skills:gmail
        {--action= : labels_get|messages_get|message_get|message_modify|draft_create|message_send|thread_get|image_message_send|inbox_dashboard_get}
        {--user-id=me : Gmail user id}
        {--message-id= : Gmail message id}
        {--thread-id= : Gmail thread id}
        {--to-email= : Mail target email}
        {--subject= : Mail subject}
        {--text-body= : Mail text body}
        {--query= : JSON query parameters}
        {--payload= : JSON payload}
        {--file-path= : Image file path}
        {--file-name= : Image file name override}
        {--content-type=image/jpeg : Image content type}';

    public $description = 'Run Gmail common read/write actions through the Laravel service layer';

    public function __construct(public hahaha_service_agents_skills_gmail $service_)
    {
        parent::__construct();
    }

    /**
     * 依 action 分派 Gmail service 常用能力。
     */
    public function handle(): int
    {
        try {
            $action_ = $this->Required_Option_Resolve('action');
            $query_parameters_ = $this->Json_Option_Resolve('query');
            $payload_ = $this->Json_Option_Resolve('payload');
            $user_id_ = $this->Optional_Option_Resolve('user-id') ?: 'me';

            $result_ = match ($action_) {
                'labels_get' => ['labels' => $this->service_->Labels_Get($user_id_)],
                'messages_get' => ['messages' => $this->service_->Messages_Get($user_id_, $query_parameters_)],
                'message_get' => $this->service_->Message_Get($this->Required_Option_Resolve('message-id'), $user_id_, $query_parameters_),
                'message_modify' => $this->service_->Message_Modify($this->Required_Option_Resolve('message-id'), $payload_, $user_id_),
                'draft_create' => $this->service_->Draft_Create($payload_, $user_id_),
                'message_send' => $this->service_->Message_Send($payload_, $user_id_),
                'thread_get' => $this->service_->Thread_Get($this->Required_Option_Resolve('thread-id'), $user_id_, $query_parameters_),
                'image_message_send' => $this->service_->Image_Message_Send(
                    $this->Required_Option_Resolve('to-email'),
                    $this->Required_Option_Resolve('subject'),
                    $this->Required_Option_Resolve('text-body'),
                    $this->File_Name_Resolve(),
                    $this->File_Content_Resolve('file-path'),
                    $this->Optional_Option_Resolve('content-type'),
                    $user_id_,
                ),
                'inbox_dashboard_get' => $this->service_->Inbox_Dashboard_Get($user_id_, $query_parameters_),
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
     * 解析圖片郵件用的檔名，未指定時自動取自 file path。
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
