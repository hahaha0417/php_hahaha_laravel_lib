<?php

namespace L_Lib\Services\agents\skills\line;

use Illuminate\Http\Client\PendingRequest;
use L_Lib\Services\agents\skills\common\hahaha_service_agents_skills_http_base;

/**
 * 提供 LINE 常用 bot、group、rich menu、dashboard、圖片與卡片訊息讀寫。
 */
class hahaha_service_agents_skills_line extends hahaha_service_agents_skills_http_base
{
    /**
     * 讀取 LINE bot 基本資料。
     */
    public function Bot_Info_Get(): array
    {
        return $this->Get('info');
    }

    /**
     * 讀取 LINE group 摘要資料。
     */
    public function Group_Summary_Get(string $group_id): array
    {
        return $this->Get("group/{$group_id}/summary");
    }

    /**
     * 讀取 rich menu 清單。
     *
     * @return array<int, array<string, mixed>>
     */
    public function Rich_Menus_Get(): array
    {
        $response_ = $this->Get('richmenu/list');

        return array_values($response_['richmenus'] ?? []);
    }

    /**
     * 組合 bot、rich menu、followers 作為 dashboard 資料。
     *
     * @param array<string, mixed> $query_parameters_
     */
    public function Dashboard_Get(array $query_parameters = []): array
    {
        $followers_date_ = (string) ($query_parameters['date'] ?? '');

        return [
            'bot' => $this->Bot_Info_Get(),
            'richmenus' => $this->Rich_Menus_Get(),
            'followers' => $followers_date_ === '' ? [] : $this->Followers_Get($followers_date_),
        ];
    }

    /**
     * 讀取指定日期的 followers 統計。
     */
    public function Followers_Get(string $date): array
    {
        return $this->Get('insight/followers', [
            'date' => $date,
        ]);
    }

    /**
     * 推送純文字訊息。
     */
    public function Text_Message_Push(string $to_id, string $text): array
    {
        return $this->Post('message/push', [
            'to' => $to_id,
            'messages' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
        ]);
    }

    /**
     * 推送圖片訊息。
     */
    public function Image_Message_Push(
        string $to_id,
        string $original_content_url,
        string $preview_image_url,
    ): array {
        return $this->Post('message/push', [
            'to' => $to_id,
            'messages' => [
                [
                    'type' => 'image',
                    'originalContentUrl' => $original_content_url,
                    'previewImageUrl' => $preview_image_url,
                ],
            ],
        ]);
    }

    /**
     * 推送 flex card 訊息，適合卡片型 UI。
     *
     * @param array<string, mixed> $contents_
     */
    public function Flex_Card_Message_Push(string $to_id, string $alt_text, array $contents): array
    {
        return $this->Post('message/push', [
            'to' => $to_id,
            'messages' => [
                [
                    'type' => 'flex',
                    'altText' => $alt_text,
                    'contents' => $contents,
                ],
            ],
        ]);
    }

    /**
     * 建立 rich menu。
     *
     * @param array<string, mixed> $payload_
     */
    public function Rich_Menu_Create(array $payload): array
    {
        return $this->Post('richmenu', $payload);
    }

    /**
     * 統一處理 LINE GET request。
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
     * 統一處理 LINE POST request。
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
     * 讀取 LINE 服務設定。
     *
     * @return array<string, mixed>
     */
    public function Config_Get(): array
    {
        $config_ = config('services.line', []);

        if (! is_array($config_)) {
            return [];
        }

        return $config_;
    }

    /**
     * 建立 LINE bearer token request。
     */
    public function Request_Create(): PendingRequest
    {
        $config_ = $this->Config_Get();
        $channel_access_token_ = $this->Config_String_Get($config_, 'channel_access_token');

        return $this->Http_Client_Create($config_)
            ->withToken($channel_access_token_);
    }
}
