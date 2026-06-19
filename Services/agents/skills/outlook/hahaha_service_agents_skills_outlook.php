<?php

namespace L_Lib\Services\agents\skills\outlook;

use Illuminate\Http\Client\PendingRequest;
use L_Lib\Services\agents\skills\common\hahaha_service_agents_skills_http_base;

/**
 * 提供 Outlook 常用 inbox、message、calendar、dashboard、圖片附件讀寫。
 */
class hahaha_service_agents_skills_outlook extends hahaha_service_agents_skills_http_base
{
    /**
     * 讀取 Outlook mail folders 清單。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<int, array<string, mixed>>
     */
    public function Mail_Folders_Get(string $user_id = 'me', array $query_parameters = []): array
    {
        $response_ = $this->Get("users/{$user_id}/mailFolders", $query_parameters);

        return array_values($response_['value'] ?? []);
    }

    /**
     * 讀取 Outlook messages 清單。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<int, array<string, mixed>>
     */
    public function Messages_Get(string $user_id = 'me', array $query_parameters = []): array
    {
        $response_ = $this->Get("users/{$user_id}/messages", $query_parameters);

        return array_values($response_['value'] ?? []);
    }

    /**
     * 讀取單一 message 詳細資料。
     *
     * @param array<string, mixed> $query_parameters_
     */
    public function Message_Get(string $message_id, string $user_id = 'me', array $query_parameters = []): array
    {
        return $this->Get("users/{$user_id}/messages/{$message_id}", $query_parameters);
    }

    /**
     * 更新 message 欄位，例如已讀、分類、旗標。
     *
     * @param array<string, mixed> $payload_
     */
    public function Message_Update(string $message_id, array $payload, string $user_id = 'me'): array
    {
        return $this->Patch("users/{$user_id}/messages/{$message_id}", $payload);
    }

    /**
     * 建立 draft message。
     *
     * @param array<string, mixed> $payload_
     */
    public function Draft_Create(array $payload, string $user_id = 'me'): array
    {
        return $this->Post("users/{$user_id}/messages", $payload);
    }

    /**
     * 直接送出 email。
     *
     * @param array<string, mixed> $payload_
     */
    public function Message_Send(array $payload, string $user_id = 'me'): array
    {
        return $this->Post("users/{$user_id}/sendMail", $payload);
    }

    /**
     * 在 message 或 draft 上新增圖片附件。
     *
     * @param array<string, mixed> $payload_
     */
    public function Message_Image_Add(
        string $message_id,
        string $file_name,
        string $file_content,
        string $content_type = 'image/jpeg',
        array $payload = [],
        string $user_id = 'me',
    ): array {
        return $this->Post("users/{$user_id}/messages/{$message_id}/attachments", array_merge([
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $file_name,
            'contentType' => $content_type,
            'contentBytes' => base64_encode($file_content),
        ], $payload));
    }

    /**
     * 讀取 calendar events 清單。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<int, array<string, mixed>>
     */
    public function Calendar_Events_Get(string $user_id = 'me', array $query_parameters = []): array
    {
        $response_ = $this->Get("users/{$user_id}/events", $query_parameters);

        return array_values($response_['value'] ?? []);
    }

    /**
     * 組合 folders、messages、events 作為 inbox dashboard。
     *
     * @param array<string, mixed> $message_query_parameters_
     * @param array<string, mixed> $event_query_parameters_
     * @return array<string, mixed>
     */
    public function Inbox_Dashboard_Get(
        string $user_id = 'me',
        array $message_query_parameters = [],
        array $event_query_parameters = [],
    ): array {
        return [
            'folders' => $this->Mail_Folders_Get($user_id),
            'messages' => $this->Messages_Get($user_id, $message_query_parameters),
            'events' => $this->Calendar_Events_Get($user_id, $event_query_parameters),
        ];
    }

    /**
     * 統一處理 Outlook GET request。
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
     * 統一處理 Outlook POST request。
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
     * 統一處理 Outlook PATCH request。
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
     * 讀取 Outlook 服務設定。
     *
     * @return array<string, mixed>
     */
    public function Config_Get(): array
    {
        $config_ = config('services.outlook', []);

        if (! is_array($config_)) {
            return [];
        }

        return $config_;
    }

    /**
     * 建立 Outlook Graph bearer token request。
     */
    public function Request_Create(): PendingRequest
    {
        $config_ = $this->Config_Get();
        $token_ = $this->Config_String_Get($config_, 'token');

        return $this->Http_Client_Create($config_)
            ->withToken($token_);
    }
}
