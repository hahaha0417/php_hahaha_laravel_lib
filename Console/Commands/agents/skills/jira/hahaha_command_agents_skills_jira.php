<?php

namespace L_Lib\Console\Commands\agents\skills\jira;

use InvalidArgumentException;
use L_Lib\Console\Commands\agents\skills\common\hahaha_command_agents_skills_base;
use L_Lib\Services\agents\skills\jira\hahaha_service_agents_skills_jira;

/**
 * 提供 Jira 常用 issue、board、dashboard、image 類 artisan 操作入口。
 */
class hahaha_command_agents_skills_jira extends hahaha_command_agents_skills_base
{
    public $signature = 'l_lib:agents:skills:jira
        {--action= : project_get|issue_get|issues_search|issue_create|issue_update|issue_transition|issue_comment_add|issue_image_add|board_get|board_dashboard_get}
        {--project-key= : Jira project key}
        {--issue-key= : Jira issue key}
        {--board-id= : Jira board id}
        {--transition-id= : Jira transition id}
        {--comment= : Comment text}
        {--query= : JSON query parameters}
        {--payload= : JSON payload}
        {--file-path= : Image file path}
        {--file-name= : Image file name override}
        {--content-type=image/jpeg : Image content type}';

    public $description = 'Run Jira common read/write actions through the Laravel service layer';

    public function __construct(public hahaha_service_agents_skills_jira $service_)
    {
        parent::__construct();
    }

    /**
     * 依 action 分派 Jira service 常用能力。
     */
    public function handle(): int
    {
        try {
            $action_ = $this->Required_Option_Resolve('action');
            $query_parameters_ = $this->Json_Option_Resolve('query');
            $payload_ = $this->Json_Option_Resolve('payload');

            $result_ = match ($action_) {
                'project_get' => $this->service_->Project_Get($this->Required_Option_Resolve('project-key'), $query_parameters_),
                'issue_get' => $this->service_->Issue_Get($this->Required_Option_Resolve('issue-key'), $query_parameters_),
                'issues_search' => $this->service_->Issues_Search($payload_),
                'issue_create' => $this->service_->Issue_Create($payload_),
                'issue_update' => $this->service_->Issue_Update($this->Required_Option_Resolve('issue-key'), $payload_),
                'issue_transition' => $this->service_->Issue_Transition($this->Required_Option_Resolve('issue-key'), $this->Required_Option_Resolve('transition-id')),
                'issue_comment_add' => $this->service_->Issue_Comment_Add($this->Required_Option_Resolve('issue-key'), $this->Required_Option_Resolve('comment')),
                'issue_image_add' => $this->service_->Issue_Image_Add(
                    $this->Required_Option_Resolve('issue-key'),
                    $this->File_Name_Resolve(),
                    $this->File_Content_Resolve('file-path'),
                    $this->Optional_Option_Resolve('content-type'),
                    $payload_,
                ),
                'board_get' => $this->service_->Board_Get($this->Required_Option_Resolve('board-id'), $query_parameters_),
                'board_dashboard_get' => $this->service_->Board_Dashboard_Get($this->Required_Option_Resolve('board-id'), $query_parameters_),
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
