<?php

namespace L_Lib\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use L_Lib\Services\agents\skills\gmail\hahaha_service_agents_skills_gmail;
use Tests\TestCase;

class hahaha_test_agents_skills_gmail_service_Test extends TestCase
{
    public function test_messages_get_uses_gmail_bearer_token(): void
    {
        config()->set('services.gmail', [
            'base_url' => 'https://gmail.example.test/gmail/v1',
            'token' => 'gmail-token',
            'timeout' => 10,
            'connect_timeout' => 3,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'gmail.example.test/*' => Http::response([
                'messages' => [
                    ['id' => 'message-1'],
                ],
            ]),
        ]);

        $service_ = app(hahaha_service_agents_skills_gmail::class);
        $messages_ = $service_->Messages_Get();

        $this->assertSame('message-1', $messages_[0]['id']);

        Http::assertSent(function (Request $request_): bool {
            return $request_->url() === 'https://gmail.example.test/gmail/v1/users/me/messages'
                && $request_->method() === 'GET'
                && $request_->header('Authorization') === ['Bearer gmail-token'];
        });
    }

    public function test_image_message_send_builds_raw_mime_payload(): void
    {
        config()->set('services.gmail', [
            'base_url' => 'https://gmail.example.test/gmail/v1',
            'token' => 'gmail-token',
            'timeout' => 10,
            'connect_timeout' => 3,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'gmail.example.test/*' => Http::response([
                'id' => 'sent-1',
            ]),
        ]);

        $service_ = app(hahaha_service_agents_skills_gmail::class);
        $sent_ = $service_->Image_Message_Send('to@example.test', 'Hello', 'Body', 'demo.jpg', 'image-body');

        $this->assertSame('sent-1', $sent_['id']);

        Http::assertSent(function (Request $request_): bool {
            return $request_->url() === 'https://gmail.example.test/gmail/v1/users/me/messages/send'
                && $request_->method() === 'POST'
                && str_contains((string) $request_->body(), '"raw"');
        });
    }
}
