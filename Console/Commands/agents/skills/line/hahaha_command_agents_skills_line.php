<?php

namespace L_Lib\Console\Commands\agents\skills\line;

use InvalidArgumentException;
use L_Lib\Console\Commands\agents\skills\common\hahaha_command_agents_skills_base;
use L_Lib\Services\agents\skills\line\hahaha_service_agents_skills_line;

/**
 * 提供 LINE 常用 bot、dashboard、image、flex card 類 artisan 操作入口。
 */
class hahaha_command_agents_skills_line extends hahaha_command_agents_skills_base
{
    public $signature = 'l_lib:agents:skills:line
        {--action= : bot_info_get|group_summary_get|rich_menus_get|dashboard_get|text_message_push|image_message_push|flex_card_message_push|rich_menu_create}
        {--group-id= : LINE group id}
        {--to-id= : LINE user or group target id}
        {--text= : Message text}
        {--alt-text= : Flex message alt text}
        {--original-content-url= : LINE image original url}
        {--preview-image-url= : LINE image preview url}
        {--query= : JSON query parameters}
        {--payload= : JSON payload}';

    public $description = 'Run LINE common read/write actions through the Laravel service layer';

    public function __construct(public hahaha_service_agents_skills_line $service_)
    {
        parent::__construct();
    }

    /**
     * 依 action 分派 LINE service 常用能力。
     */
    public function handle(): int
    {
        try {
            $action_ = $this->Required_Option_Resolve('action');
            $query_parameters_ = $this->Json_Option_Resolve('query');
            $payload_ = $this->Json_Option_Resolve('payload');

            $result_ = match ($action_) {
                'bot_info_get' => $this->service_->Bot_Info_Get(),
                'group_summary_get' => $this->service_->Group_Summary_Get($this->Required_Option_Resolve('group-id')),
                'rich_menus_get' => ['richmenus' => $this->service_->Rich_Menus_Get()],
                'dashboard_get' => $this->service_->Dashboard_Get($query_parameters_),
                'text_message_push' => $this->service_->Text_Message_Push($this->Required_Option_Resolve('to-id'), $this->Required_Option_Resolve('text')),
                'image_message_push' => $this->service_->Image_Message_Push(
                    $this->Required_Option_Resolve('to-id'),
                    $this->Required_Option_Resolve('original-content-url'),
                    $this->Required_Option_Resolve('preview-image-url'),
                ),
                'flex_card_message_push' => $this->service_->Flex_Card_Message_Push(
                    $this->Required_Option_Resolve('to-id'),
                    $this->Required_Option_Resolve('alt-text'),
                    $payload_,
                ),
                'rich_menu_create' => $this->service_->Rich_Menu_Create($payload_),
                default => throw new InvalidArgumentException('Unsupported action: '.$action_),
            };
        } catch (InvalidArgumentException $exception_) {
            $this->components->error($exception_->getMessage());

            return self::FAILURE;
        }

        $this->Result_Output($result_);

        return self::SUCCESS;
    }
}
