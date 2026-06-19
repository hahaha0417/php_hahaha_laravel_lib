<?php

namespace L_Lib\Services\agents\skills\jira;

use Illuminate\Http\Client\PendingRequest;
use L_Lib\Services\agents\skills\common\hahaha_service_agents_skills_http_base;

/**
 * 提供 Jira 常用 project、issue、board、dashboard、圖片附件讀寫。
 */
class hahaha_service_agents_skills_jira extends hahaha_service_agents_skills_http_base
{
    /**
     * 讀取單一 project 基本資料。
     *
     * @param array<string, mixed> $query_parameters_
     */
    public function Project_Get(string $project_key, array $query_parameters = []): array
    {
        return $this->Get("project/{$project_key}", $query_parameters);
    }

    /**
     * 讀取單一 issue 詳細資料。
     *
     * @param array<string, mixed> $query_parameters_
     */
    public function Issue_Get(string $issue_key, array $query_parameters = []): array
    {
        return $this->Get("issue/{$issue_key}", $query_parameters);
    }

    /**
     * 使用 search API 查詢 issue 清單或 dashboard 資料來源。
     *
     * @param array<string, mixed> $payload_
     * @return array<string, mixed>
     */
    public function Issues_Search(array $payload): array
    {
        return $this->Post('search', $payload);
    }

    /**
     * 建立 Jira issue。
     *
     * @param array<string, mixed> $payload_
     */
    public function Issue_Create(array $payload): array
    {
        return $this->Post('issue', $payload);
    }

    /**
     * 更新 Jira issue 欄位內容。
     *
     * @param array<string, mixed> $payload_
     */
    public function Issue_Update(string $issue_key, array $payload): array
    {
        return $this->Put("issue/{$issue_key}", $payload);
    }

    /**
     * 讀取 issue 可用 transition 清單。
     *
     * @return array<int, array<string, mixed>>
     */
    public function Issue_Transitions_Get(string $issue_key): array
    {
        $response_ = $this->Get("issue/{$issue_key}/transitions");

        return array_values($response_['transitions'] ?? []);
    }

    /**
     * 執行 issue transition，例如 To Do -> In Progress。
     */
    public function Issue_Transition(string $issue_key, string $transition_id): array
    {
        return $this->Post("issue/{$issue_key}/transitions", [
            'transition' => [
                'id' => $transition_id,
            ],
        ]);
    }

    /**
     * 在 issue 下新增 comment。
     */
    public function Issue_Comment_Add(string $issue_key, string $comment_text): array
    {
        return $this->Post("issue/{$issue_key}/comment", [
            'body' => $comment_text,
        ]);
    }

    /**
     * 更新 issue assignee。
     *
     * @param array<string, mixed> $payload_
     */
    public function Issue_Assignee_Update(string $issue_key, array $payload): array
    {
        return $this->Put("issue/{$issue_key}/assignee", $payload);
    }

    /**
     * 上傳 issue 圖片或附件。
     *
     * @param array<string, mixed> $payload_
     */
    public function Issue_Image_Add(
        string $issue_key,
        string $file_name,
        string $file_content,
        string $content_type = 'image/jpeg',
        array $payload = [],
    ): array {
        $request_ = $this->Request_Create()
            ->withHeaders([
                'X-Atlassian-Token' => 'no-check',
            ])
            ->attach('file', $file_content, $file_name, [
                'Content-Type' => $content_type,
            ]);

        if ($payload !== []) {
            $request_ = $request_->asMultipart();
        }

        return $request_
            ->post("issue/{$issue_key}/attachments", $payload)
            ->throw()
            ->json();
    }

    /**
     * 讀取 Jira dashboard 基本資料。
     *
     * @param array<string, mixed> $query_parameters_
     */
    public function Dashboard_Get(string $dashboard_id, array $query_parameters = []): array
    {
        return $this->Get("dashboard/{$dashboard_id}", $query_parameters);
    }

    /**
     * 讀取 Jira board 基本資料。
     *
     * @param array<string, mixed> $query_parameters_
     */
    public function Board_Get(int|string $board_id, array $query_parameters = []): array
    {
        return $this->Agile_Get("board/{$board_id}", $query_parameters);
    }

    /**
     * 讀取 board 內 issue 清單。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<string, mixed>
     */
    public function Board_Issues_Get(int|string $board_id, array $query_parameters = []): array
    {
        return $this->Agile_Get("board/{$board_id}/issue", $query_parameters);
    }

    /**
     * 一次回傳 board 與其 issues，方便 dashboard 畫面使用。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<string, mixed>
     */
    public function Board_Dashboard_Get(int|string $board_id, array $query_parameters = []): array
    {
        return [
            'board' => $this->Board_Get($board_id),
            'issues' => $this->Board_Issues_Get($board_id, $query_parameters),
        ];
    }

    /**
     * 統一處理 Jira core API GET request。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<string, mixed>
     */
    public function Get(string $path, array $query_parameters = []): array
    {
        return $this->Request_Create()
            ->get($path, $query_parameters)
            ->throw()
            ->json();
    }

    /**
     * 統一處理 Jira core API POST request。
     *
     * @param array<string, mixed> $payload_
     * @return array<string, mixed>
     */
    public function Post(string $path, array $payload): array
    {
        return $this->Request_Create()
            ->post($path, $payload)
            ->throw()
            ->json();
    }

    /**
     * 統一處理 Jira core API PUT request。
     *
     * @param array<string, mixed> $payload_
     * @return array<string, mixed>
     */
    public function Put(string $path, array $payload): array
    {
        return $this->Request_Create()
            ->put($path, $payload)
            ->throw()
            ->json();
    }

    /**
     * 統一處理 Jira agile API GET request。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<string, mixed>
     */
    public function Agile_Get(string $path, array $query_parameters = []): array
    {
        return $this->Request_Agile_Create()
            ->get($path, $query_parameters)
            ->throw()
            ->json();
    }

    /**
     * 讀取 Jira 服務設定。
     *
     * @return array<string, mixed>
     */
    public function Config_Get(): array
    {
        $config_ = config('services.jira', []);

        if (! is_array($config_)) {
            return [];
        }

        return $config_;
    }

    /**
     * 建立 Jira core API request，使用 basic auth。
     */
    public function Request_Create(): PendingRequest
    {
        $config_ = $this->Config_Get();
        $email_ = $this->Config_String_Get($config_, 'email');
        $api_token_ = $this->Config_String_Get($config_, 'api_token');

        return $this->Http_Client_Create($config_)
            ->withBasicAuth($email_, $api_token_);
    }

    /**
     * 建立 Jira agile API request，供 board 類資料使用。
     */
    public function Request_Agile_Create(): PendingRequest
    {
        $config_ = $this->Config_Get();
        $email_ = $this->Config_String_Get($config_, 'email');
        $api_token_ = $this->Config_String_Get($config_, 'api_token');
        $agile_base_url_ = $this->Config_String_Get(
            $config_,
            'agile_base_url',
            $this->Config_String_Get($config_, 'base_url')
        );

        return $this->Http_Client_Create($this->Config_Base_Url_Replace($config_, $agile_base_url_))
            ->withBasicAuth($email_, $api_token_);
    }
}
