<?php

namespace L_Lib\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use L_Lib\Services\agents\skills\line\hahaha_service_agents_skills_line;
use Tests\TestCase;

class hahaha_test_agents_skills_line_service_Test extends TestCase
{
    public function test_bot_info_get_uses_line_bearer_token(): void
    {
        config()->set('services.line', [
            'base_url' => 'https://line.example.test/v2/bot',
            'channel_access_token' => 'line-token',
            'timeout' => 10,
            'connect_timeout' => 3,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'line.example.test/*' => Http::response([
                'userId' => 'bot-1',
            ]),
        ]);

        $service_ = app(hahaha_service_agents_skills_line::class);
        $bot_ = $service_->Bot_Info_Get();

        $this->assertSame('bot-1', $bot_['userId']);

        Http::assertSent(function (Request $request_): bool {
            return $request_->url() === 'https://line.example.test/v2/bot/info'
                && $request_->method() === 'GET'
                && $request_->header('Authorization') === ['Bearer line-token'];
        });
    }

    public function test_flex_card_message_push_sends_contents_payload(): void
    {
        config()->set('services.line', [
            'base_url' => 'https://line.example.test/v2/bot',
            'channel_access_token' => 'line-token',
            'timeout' => 10,
            'connect_timeout' => 3,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'line.example.test/*' => Http::response([]),
        ]);

        $service_ = app(hahaha_service_agents_skills_line::class);
        $result_ = $service_->Flex_Card_Message_Push('user-1', 'Card', [
            'type' => 'bubble',
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [],
            ],
        ]);

        $this->assertSame([], $result_);

        Http::assertSent(function (Request $request_): bool {
            return $request_->url() === 'https://line.example.test/v2/bot/message/push'
                && $request_->method() === 'POST'
                && str_contains((string) $request_->body(), '"flex"');
        });
    }
}
