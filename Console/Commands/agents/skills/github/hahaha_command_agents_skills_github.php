<?php

namespace L_Lib\Console\Commands\agents\skills\github;

use InvalidArgumentException;
use L_Lib\Console\Commands\agents\skills\common\hahaha_command_agents_skills_base;
use L_Lib\Services\agents\skills\github\hahaha_service_agents_skills_github;

/**
 * 提供 GitHub 常用 repo、issue、contents、image 類 artisan 操作入口。
 */
class hahaha_command_agents_skills_github extends hahaha_command_agents_skills_base
{
    public $signature = 'l_lib:agents:skills:github
        {--action= : repo_get|repo_dashboard_get|issue_get|issue_create|issue_update|issue_comment_add|content_get|image_create_or_update}
        {--owner= : GitHub repo owner}
        {--repo= : GitHub repo name}
        {--issue-number= : GitHub issue number}
        {--path= : GitHub content path}
        {--message= : Commit or comment text}
        {--sha= : Existing file sha}
        {--query= : JSON query parameters}
        {--payload= : JSON payload}
        {--file-path= : Image file path}';

    public $description = 'Run GitHub common read/write actions through the Laravel service layer';

    public function __construct(public hahaha_service_agents_skills_github $service_)
    {
        parent::__construct();
    }

    /**
     * 依 action 分派 GitHub service 常用能力。
     */
    public function handle(): int
    {
        try {
            $action_ = $this->Required_Option_Resolve('action');
            $query_parameters_ = $this->Json_Option_Resolve('query');
            $payload_ = $this->Json_Option_Resolve('payload');
            $owner_ = $this->Required_Option_Resolve('owner');
            $repo_ = $this->Required_Option_Resolve('repo');

            $result_ = match ($action_) {
                'repo_get' => $this->service_->Repo_Get($owner_, $repo_, $query_parameters_),
                'repo_dashboard_get' => $this->service_->Repo_Dashboard_Get($owner_, $repo_, $query_parameters_),
                'issue_get' => $this->service_->Issue_Get($owner_, $repo_, (int) $this->Required_Option_Resolve('issue-number'), $query_parameters_),
                'issue_create' => $this->service_->Issue_Create($owner_, $repo_, $payload_),
                'issue_update' => $this->service_->Issue_Update($owner_, $repo_, (int) $this->Required_Option_Resolve('issue-number'), $payload_),
                'issue_comment_add' => $this->service_->Issue_Comment_Add($owner_, $repo_, (int) $this->Required_Option_Resolve('issue-number'), $this->Required_Option_Resolve('message')),
                'content_get' => $this->service_->Content_Get($owner_, $repo_, $this->Required_Option_Resolve('path'), $query_parameters_),
                'image_create_or_update' => $this->service_->Image_Create_Or_Update(
                    $owner_,
                    $repo_,
                    $this->Required_Option_Resolve('path'),
                    $this->File_Content_Resolve('file-path'),
                    $this->Required_Option_Resolve('message'),
                    $this->Optional_Option_Resolve('sha'),
                ),
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
