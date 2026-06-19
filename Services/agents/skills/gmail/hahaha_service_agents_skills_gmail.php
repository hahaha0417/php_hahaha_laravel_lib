<?php

namespace L_Lib\Services\agents\skills\gmail;

use Illuminate\Http\Client\PendingRequest;
use L_Lib\Services\agents\skills\common\hahaha_service_agents_skills_http_base;

/**
 * 提供 Gmail 常用 inbox、thread、draft、寄信、圖片郵件讀寫。
 */
class hahaha_service_agents_skills_gmail extends hahaha_service_agents_skills_http_base
{
    /**
     * 讀取 Gmail labels 清單。
     *
     * @return array<int, array<string, mixed>>
     */
    public function Labels_Get(string $user_id = 'me'): array
    {
        $response_ = $this->Get("users/{$user_id}/labels");

        return array_values($response_['labels'] ?? []);
    }

    /**
     * 讀取 Gmail messages 清單。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<int, array<string, mixed>>
     */
    public function Messages_Get(string $user_id = 'me', array $query_parameters = []): array
    {
        $response_ = $this->Get("users/{$user_id}/messages", $query_parameters);

        return array_values($response_['messages'] ?? []);
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
     * 修改 message labels 或狀態。
     *
     * @param array<string, mixed> $payload_
     */
    public function Message_Modify(string $message_id, array $payload, string $user_id = 'me'): array
    {
        return $this->Post("users/{$user_id}/messages/{$message_id}/modify", $payload);
    }

    /**
     * 建立 draft message。
     *
     * @param array<string, mixed> $payload_
     */
    public function Draft_Create(array $payload, string $user_id = 'me'): array
    {
        return $this->Post("users/{$user_id}/drafts", $payload);
    }

    /**
     * 送出 Gmail message。
     *
     * @param array<string, mixed> $payload_
     */
    public function Message_Send(array $payload, string $user_id = 'me'): array
    {
        return $this->Post("users/{$user_id}/messages/send", $payload);
    }

    /**
     * 讀取 thread 詳細資料。
     *
     * @param array<string, mixed> $query_parameters_
     */
    public function Thread_Get(string $thread_id, string $user_id = 'me', array $query_parameters = []): array
    {
        return $this->Get("users/{$user_id}/threads/{$thread_id}", $query_parameters);
    }

    /**
     * 讀取單一附件資料。
     *
     * @param array<string, mixed> $query_parameters_
     */
    public function Attachment_Get(
        string $message_id,
        string $attachment_id,
        string $user_id = 'me',
        array $query_parameters = [],
    ): array {
        return $this->Get("users/{$user_id}/messages/{$message_id}/attachments/{$attachment_id}", $query_parameters);
    }

    /**
     * 組合 labels 與 messages，作為 inbox dashboard。
     *
     * @param array<string, mixed> $message_query_parameters_
     * @return array<string, mixed>
     */
    public function Inbox_Dashboard_Get(string $user_id = 'me', array $message_query_parameters = []): array
    {
        return [
            'labels' => $this->Labels_Get($user_id),
            'messages' => $this->Messages_Get($user_id, $message_query_parameters),
        ];
    }

    /**
     * 建立含圖片附件的 raw MIME 郵件內容。
     */
    public function Raw_Image_Mime_Message_Build(
        string $to_email,
        string $subject,
        string $text_body,
        string $file_name,
        string $file_content,
        string $content_type = 'image/jpeg',
    ): string {
        $boundary_ = 'boundary_'.md5($to_email.$subject.$file_name);

        $mime_text_ = implode("\r\n", [
            'To: '.$to_email,
            'Subject: '.$subject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="'.$boundary_.'"',
            '',
            '--'.$boundary_,
            'Content-Type: text/plain; charset=UTF-8',
            '',
            $text_body,
            '',
            '--'.$boundary_,
            'Content-Type: '.$content_type.'; name="'.$file_name.'"',
            'Content-Transfer-Encoding: base64',
            'Content-Disposition: attachment; filename="'.$file_name.'"',
            '',
            chunk_split(base64_encode($file_content)),
            '--'.$boundary_.'--',
            '',
        ]);

        return rtrim(strtr(base64_encode($mime_text_), '+/', '-_'), '=');
    }

    /**
     * 直接送出一封帶圖片附件的 Gmail 郵件。
     */
    public function Image_Message_Send(
        string $to_email,
        string $subject,
        string $text_body,
        string $file_name,
        string $file_content,
        string $content_type = 'image/jpeg',
        string $user_id = 'me',
    ): array {
        return $this->Message_Send([
            'raw' => $this->Raw_Image_Mime_Message_Build(
                $to_email,
                $subject,
                $text_body,
                $file_name,
                $file_content,
                $content_type,
            ),
        ], $user_id);
    }

    /**
     * 統一處理 Gmail GET request。
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
     * 統一處理 Gmail POST request。
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
     * 讀取 Gmail 服務設定。
     *
     * @return array<string, mixed>
     */
    public function Config_Get(): array
    {
        $config_ = config('services.gmail', []);

        if (! is_array($config_)) {
            return [];
        }

        return $config_;
    }

    /**
     * 建立 Gmail bearer token request。
     */
    public function Request_Create(): PendingRequest
    {
        $config_ = $this->Config_Get();
        $token_ = $this->Config_String_Get($config_, 'token');

        return $this->Http_Client_Create($config_)
            ->withToken($token_);
    }
}
