<?php

namespace L_Lib\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use L_Lib\Services\agents\skills\jira\hahaha_service_agents_skills_jira;
use Tests\TestCase;

class hahaha_test_agents_skills_jira_service_Test extends TestCase
{
    public function test_issue_get_uses_basic_auth_and_configured_base_url(): void
    {
        config()->set('services.jira', [
            'base_url' => 'https://jira.example.test/rest/api/3',
            'email' => 'bot@example.test',
            'api_token' => 'jira-token',
            'timeout' => 8,
            'connect_timeout' => 2,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'jira.example.test/*' => Http::response([
                'key' => 'ABC-123',
            ]),
        ]);

        $service_ = app(hahaha_service_agents_skills_jira::class);
        $issue_ = $service_->Issue_Get('ABC-123', [
            'fields' => 'summary',
        ]);

        $this->assertSame('ABC-123', $issue_['key']);

        Http::assertSent(function (Request $request_): bool {
            $authorization_header_ = $request_->header('Authorization');

            return $request_->url() === 'https://jira.example.test/rest/api/3/issue/ABC-123?fields=summary'
                && $request_->method() === 'GET'
                && $authorization_header_ === ['Basic '.base64_encode('bot@example.test:jira-token')];
        });
    }

    public function test_issue_image_add_uploads_attachment_with_basic_auth_and_atlassian_header(): void
    {
        config()->set('services.jira', [
            'base_url' => 'https://jira.example.test/rest/api/3',
            'agile_base_url' => 'https://jira.example.test/rest/agile/1.0',
            'email' => 'bot@example.test',
            'api_token' => 'jira-token',
            'timeout' => 8,
            'connect_timeout' => 2,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'jira.example.test/*' => Http::response([
                'id' => '10001',
            ]),
        ]);

        $service_ = app(hahaha_service_agents_skills_jira::class);
        $attachment_ = $service_->Issue_Image_Add('ABC-123', 'demo.jpg', 'image-body', 'image/jpeg');

        $this->assertSame('10001', $attachment_['id']);

        Http::assertSent(function (Request $request_): bool {
            $authorization_header_ = $request_->header('Authorization');
            $atlassian_header_ = $request_->header('X-Atlassian-Token');

            return $request_->url() === 'https://jira.example.test/rest/api/3/issue/ABC-123/attachments'
                && $request_->method() === 'POST'
                && $authorization_header_ === ['Basic '.base64_encode('bot@example.test:jira-token')]
                && $atlassian_header_ === ['no-check']
                && str_contains((string) $request_->body(), 'demo.jpg');
        });
    }
}
