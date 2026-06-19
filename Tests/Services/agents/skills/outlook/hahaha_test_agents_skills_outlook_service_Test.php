<?php

namespace L_Lib\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use L_Lib\Services\agents\skills\outlook\hahaha_service_agents_skills_outlook;
use Tests\TestCase;

class hahaha_test_agents_skills_outlook_service_Test extends TestCase
{
    public function test_messages_get_uses_outlook_bearer_token(): void
    {
        config()->set('services.outlook', [
            'base_url' => 'https://graph.example.test/v1.0',
            'token' => 'outlook-token',
            'timeout' => 10,
            'connect_timeout' => 3,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'graph.example.test/*' => Http::response([
                'value' => [
                    ['id' => 'message-1'],
                ],
            ]),
        ]);

        $service_ = app(hahaha_service_agents_skills_outlook::class);
        $messages_ = $service_->Messages_Get();

        $this->assertSame('message-1', $messages_[0]['id']);

        Http::assertSent(function (Request $request_): bool {
            return $request_->url() === 'https://graph.example.test/v1.0/users/me/messages'
                && $request_->method() === 'GET'
                && $request_->header('Authorization') === ['Bearer outlook-token'];
        });
    }

    public function test_message_image_add_sends_base64_attachment_payload(): void
    {
        config()->set('services.outlook', [
            'base_url' => 'https://graph.example.test/v1.0',
            'token' => 'outlook-token',
            'timeout' => 10,
            'connect_timeout' => 3,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'graph.example.test/*' => Http::response([
                'id' => 'attachment-1',
            ]),
        ]);

        $service_ = app(hahaha_service_agents_skills_outlook::class);
        $attachment_ = $service_->Message_Image_Add('message-1', 'demo.jpg', 'image-body');

        $this->assertSame('attachment-1', $attachment_['id']);

        Http::assertSent(function (Request $request_): bool {
            return $request_->url() === 'https://graph.example.test/v1.0/users/me/messages/message-1/attachments'
                && $request_->method() === 'POST'
                && str_contains((string) $request_->body(), base64_encode('image-body'));
        });
    }
}
