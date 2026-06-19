<?php

namespace L_Lib\Services\agents\skills\trello;

use Illuminate\Http\Client\PendingRequest;
use L_Lib\Services\agents\skills\common\hahaha_service_agents_skills_http_base;

/**
 * 提供 Trello 常用 board、list、card、dashboard、圖片附件讀寫。
 */
class hahaha_service_agents_skills_trello extends hahaha_service_agents_skills_http_base
{
    /**
     * 讀取單一 board 基本資料。
     *
     * @param array<string, mixed> $query_parameters_
     */
    public function Board_Get(string $board_id, array $query_parameters = []): array
    {
        return $this->Get("boards/{$board_id}", $query_parameters);
    }

    /**
     * 讀取指定 board 內的 lists。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<int, array<string, mixed>>
     */
    public function Board_Lists_Get(string $board_id, array $query_parameters = []): array
    {
        return array_values($this->Get("boards/{$board_id}/lists", $query_parameters));
    }

    /**
     * 讀取指定 board 內的 cards。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<int, array<string, mixed>>
     */
    public function Board_Cards_Get(string $board_id, array $query_parameters = []): array
    {
        return array_values($this->Get("boards/{$board_id}/cards", $query_parameters));
    }

    /**
     * 一次回傳 board、lists、cards，方便做 dashboard 畫面或同步摘要。
     *
     * @param array<string, mixed> $board_query_parameters_
     * @param array<string, mixed> $list_query_parameters_
     * @param array<string, mixed> $card_query_parameters_
     * @return array<string, mixed>
     */
    public function Board_Dashboard_Get(
        string $board_id,
        array $board_query_parameters = [],
        array $list_query_parameters = [],
        array $card_query_parameters = [],
    ): array {
        return [
            'board' => $this->Board_Get($board_id, $board_query_parameters),
            'lists' => $this->Board_Lists_Get($board_id, $list_query_parameters),
            'cards' => $this->Board_Cards_Get($board_id, $card_query_parameters),
        ];
    }

    /**
     * 讀取單一卡片資料。
     *
     * @param array<string, mixed> $query_parameters_
     */
    public function Card_Get(string $card_id, array $query_parameters = []): array
    {
        return $this->Get("cards/{$card_id}", $query_parameters);
    }

    /**
     * 讀取卡片附件清單，通常用在圖片與檔案檢查。
     *
     * @param array<string, mixed> $query_parameters_
     * @return array<int, array<string, mixed>>
     */
    public function Card_Attachments_Get(string $card_id, array $query_parameters = []): array
    {
        return array_values($this->Get("cards/{$card_id}/attachments", $query_parameters));
    }

    /**
     * 建立新卡片，list id 會自動併入 payload。
     *
     * @param array<string, mixed> $payload_
     */
    public function Card_Create(string $list_id, array $payload): array
    {
        $payload['idList'] = $list_id;

        return $this->Post('cards', $payload);
    }

    /**
     * 更新既有卡片欄位，例如名稱、描述、日期、labels。
     *
     * @param array<string, mixed> $payload_
     */
    public function Card_Update(string $card_id, array $payload): array
    {
        return $this->Put("cards/{$card_id}", $payload);
    }

    /**
     * 移動卡片到指定 list，也可同時帶其他更新欄位。
     *
     * @param array<string, mixed> $payload_
     */
    public function Card_Move(string $card_id, string $list_id, array $payload = []): array
    {
        $payload['idList'] = $list_id;

        return $this->Put("cards/{$card_id}", $payload);
    }

    /**
     * 在卡片底下新增 comment。
     */
    public function Card_Comment_Add(string $card_id, string $comment_text): array
    {
        return $this->Post("cards/{$card_id}/actions/comments", [
            'text' => $comment_text,
        ]);
    }

    /**
     * 將卡片標記為封存。
     */
    public function Card_Archive(string $card_id): array
    {
        return $this->Put("cards/{$card_id}", [
            'closed' => true,
        ]);
    }

    /**
     * 上傳卡片圖片附件，適合做封面圖或附圖。
     *
     * @param array<string, mixed> $payload_
     */
    public function Card_Image_Add(
        string $card_id,
        string $file_name,
        string $file_content,
        string $content_type = 'image/jpeg',
        array $payload = [],
    ): array {
        return $this->Request_Create()
            ->attach('file', $file_content, $file_name, [
                'Content-Type' => $content_type,
            ])
            ->post("cards/{$card_id}/attachments", $payload)
            ->throw()
            ->json();
    }

    /**
     * 刪除卡片上的指定附件。
     */
    public function Card_Attachment_Delete(string $card_id, string $attachment_id): array
    {
        return $this->Delete("cards/{$card_id}/attachments/{$attachment_id}");
    }

    /**
     * 統一處理 Trello GET request。
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
     * 統一處理 Trello POST request。
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
     * 統一處理 Trello PUT request。
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
     * 統一處理 Trello DELETE request。
     *
     * @return array<string, mixed>
     */
    public function Delete(string $path): array
    {
        return $this->Request_Create()
            ->delete($path)
            ->throw()
            ->json();
    }

    /**
     * 讀取 Trello 服務設定。
     *
     * @return array<string, mixed>
     */
    public function Config_Get(): array
    {
        $config_ = config('services.trello', []);

        if (! is_array($config_)) {
            return [];
        }

        return $config_;
    }

    /**
     * 建立已帶 Trello key 與 token 的 request。
     */
    public function Request_Create(): PendingRequest
    {
        $config_ = $this->Config_Get();
        $key_ = $this->Config_String_Get($config_, 'key');
        $token_ = $this->Config_String_Get($config_, 'token');

        return $this->Http_Client_Create($config_)
            ->withQueryParameters([
                'key' => $key_,
                'token' => $token_,
            ]);
    }
}
