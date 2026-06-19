<?php

namespace L_Lib\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use L_Lib\Services\agents\skills\trello\hahaha_service_agents_skills_trello;
use Tests\TestCase;

class hahaha_test_agents_skills_trello_service_Test extends TestCase
{
    public function test_card_get_uses_configured_trello_query_authentication(): void
    {
        config()->set('services.trello', [
            'base_url' => 'https://trello.example.test/1',
            'key' => 'trello-key',
            'token' => 'trello-token',
            'timeout' => 9,
            'connect_timeout' => 2,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'trello.example.test/*' => Http::response([
                'id' => 'card-123',
            ]),
        ]);

        $service_ = app(hahaha_service_agents_skills_trello::class);
        $card_ = $service_->Card_Get('card-123', [
            'fields' => 'name',
        ]);

        $this->assertSame('card-123', $card_['id']);

        Http::assertSent(function (Request $request_): bool {
            return $request_->url() === 'https://trello.example.test/1/cards/card-123?key=trello-key&token=trello-token&fields=name'
                && $request_->method() === 'GET';
        });
    }

    public function test_card_image_add_uploads_attachment_with_query_authentication(): void
    {
        config()->set('services.trello', [
            'base_url' => 'https://trello.example.test/1',
            'key' => 'trello-key',
            'token' => 'trello-token',
            'timeout' => 9,
            'connect_timeout' => 2,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'trello.example.test/*' => Http::response([
                'id' => 'attachment-1',
            ]),
        ]);

        $service_ = app(hahaha_service_agents_skills_trello::class);
        $attachment_ = $service_->Card_Image_Add('card-123', 'demo.jpg', 'image-body', 'image/jpeg');

        $this->assertSame('attachment-1', $attachment_['id']);

        Http::assertSent(function (Request $request_): bool {
            return $request_->url() === 'https://trello.example.test/1/cards/card-123/attachments?key=trello-key&token=trello-token'
                && $request_->method() === 'POST'
                && str_contains((string) $request_->body(), 'demo.jpg');
        });
    }
}
