<?php

namespace L_Lib\Services\agents\skills\github;

use Illuminate\Http\Client\PendingRequest;
use L_Lib\Services\agents\skills\common\hahaha_service_agents_skills_http_base;

/**
 * 提供 GitHub 常用 repo、issue、pull、dashboard、圖片檔案寫入。
 */
class hahaha_service_agents_skills_github extends hahaha_service_agents_skills_http_base
{
    /**
     * 讀取單一 repository 基本資料。
     *
     * @param array<string, mixed> $query_parameters_
     */
    public function Repo_Get(string $owner, string $repo, array $query_parameters = []): array
    {
        return $this->Get("repos/{$owner}/{$repo}", $query_parameters);
    }

    /**
     * 讀取 repository issues 清單。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<int, array<string, mixed>>
     */
    public function Repo_Issues_Get(string $owner, string $repo, array $query_parameters = []): array
    {
        return array_values($this->Get("repos/{$owner}/{$repo}/issues", $query_parameters));
    }

    /**
     * 讀取 repository pull requests 清單。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<int, array<string, mixed>>
     */
    public function Repo_Pulls_Get(string $owner, string $repo, array $query_parameters = []): array
    {
        return array_values($this->Get("repos/{$owner}/{$repo}/pulls", $query_parameters));
    }

    /**
     * 整理 repo、issues、pulls 成 dashboard 摘要。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<string, mixed>
     */
    public function Repo_Dashboard_Get(string $owner, string $repo, array $query_parameters = []): array
    {
        return [
            'repo' => $this->Repo_Get($owner, $repo),
            'issues' => $this->Repo_Issues_Get($owner, $repo, $query_parameters),
            'pulls' => $this->Repo_Pulls_Get($owner, $repo, $query_parameters),
        ];
    }

    /**
     * 讀取單一 issue 資料。
     *
     * @param array<string, mixed> $query_parameters_
     */
    public function Issue_Get(string $owner, string $repo, int $issue_number, array $query_parameters = []): array
    {
        return $this->Get("repos/{$owner}/{$repo}/issues/{$issue_number}", $query_parameters);
    }

    /**
     * 建立 GitHub issue。
     *
     * @param array<string, mixed> $payload_
     */
    public function Issue_Create(string $owner, string $repo, array $payload): array
    {
        return $this->Post("repos/{$owner}/{$repo}/issues", $payload);
    }

    /**
     * 更新 GitHub issue 欄位。
     *
     * @param array<string, mixed> $payload_
     */
    public function Issue_Update(string $owner, string $repo, int $issue_number, array $payload): array
    {
        return $this->Patch("repos/{$owner}/{$repo}/issues/{$issue_number}", $payload);
    }

    /**
     * 在 issue 下新增 comment。
     */
    public function Issue_Comment_Add(string $owner, string $repo, int $issue_number, string $comment_text): array
    {
        return $this->Post("repos/{$owner}/{$repo}/issues/{$issue_number}/comments", [
            'body' => $comment_text,
        ]);
    }

    /**
     * 讀取 repository 內指定檔案內容。
     *
     * @param array<string, mixed> $query_parameters_
     */
    public function Content_Get(string $owner, string $repo, string $path, array $query_parameters = []): array
    {
        return $this->Get("repos/{$owner}/{$repo}/contents/{$path}", $query_parameters);
    }

    /**
     * 建立或更新 repository 檔案內容。
     *
     * @param array<string, mixed> $payload_
     */
    public function Content_Create_Or_Update(string $owner, string $repo, string $path, array $payload): array
    {
        return $this->Put("repos/{$owner}/{$repo}/contents/{$path}", $payload);
    }

    /**
     * 將圖片內容轉成 base64，並以 GitHub contents API 建立或更新檔案。
     */
    public function Image_Create_Or_Update(
        string $owner,
        string $repo,
        string $path,
        string $file_content,
        string $message,
        string $sha = '',
    ): array {
        $payload_ = [
            'message' => $message,
            'content' => base64_encode($file_content),
        ];

        if ($sha !== '') {
            $payload_['sha'] = $sha;
        }

        return $this->Content_Create_Or_Update($owner, $repo, $path, $payload_);
    }

    /**
     * 統一處理 GitHub GET request。
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
     * 統一處理 GitHub POST request。
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
     * 統一處理 GitHub PUT request。
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
     * 統一處理 GitHub PATCH request。
     *
     * @param array<string, mixed> $payload_
     * @return array<string, mixed>
     */
    public function Patch(string $path, array $payload): array
    {
        return $this->Request_Create()
            ->patch($path, $payload)
            ->throw()
            ->json();
    }

    /**
     * 讀取 GitHub 服務設定。
     *
     * @return array<string, mixed>
     */
    public function Config_Get(): array
    {
        $config_ = config('services.github', []);

        if (! is_array($config_)) {
            return [];
        }

        return $config_;
    }

    /**
     * 建立 GitHub bearer token request，並帶入 API 版本 header。
     */
    public function Request_Create(): PendingRequest
    {
        $config_ = $this->Config_Get();
        $token_ = $this->Config_String_Get($config_, 'token');
        $api_version_ = $this->Config_String_Get($config_, 'api_version', '2022-11-28');

        return $this->Http_Client_Create($config_)
            ->withToken($token_)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => $api_version_,
            ]);
    }
}
