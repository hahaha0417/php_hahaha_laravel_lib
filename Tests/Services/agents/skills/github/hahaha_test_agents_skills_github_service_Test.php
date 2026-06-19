<?php

namespace L_Lib\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use L_Lib\Services\agents\skills\github\hahaha_service_agents_skills_github;
use Tests\TestCase;

class hahaha_test_agents_skills_github_service_Test extends TestCase
{
    public function test_repo_get_uses_github_bearer_token_headers(): void
    {
        config()->set('services.github', [
            'base_url' => 'https://github.example.test',
            'token' => 'github-token',
            'api_version' => '2022-11-28',
            'timeout' => 10,
            'connect_timeout' => 3,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'github.example.test/*' => Http::response([
                'full_name' => 'demo/repo',
            ]),
        ]);

        $service_ = app(hahaha_service_agents_skills_github::class);
        $repo_ = $service_->Repo_Get('demo', 'repo');

        $this->assertSame('demo/repo', $repo_['full_name']);

        Http::assertSent(function (Request $request_): bool {
            return $request_->url() === 'https://github.example.test/repos/demo/repo'
                && $request_->method() === 'GET'
                && $request_->header('Authorization') === ['Bearer github-token']
                && $request_->header('X-GitHub-Api-Version') === ['2022-11-28'];
        });
    }

    public function test_image_create_or_update_sends_base64_content_payload(): void
    {
        config()->set('services.github', [
            'base_url' => 'https://github.example.test',
            'token' => 'github-token',
            'api_version' => '2022-11-28',
            'timeout' => 10,
            'connect_timeout' => 3,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'github.example.test/*' => Http::response([
                'content' => ['path' => 'images/demo.jpg'],
            ]),
        ]);

        $service_ = app(hahaha_service_agents_skills_github::class);
        $content_ = $service_->Image_Create_Or_Update('demo', 'repo', 'images/demo.jpg', 'image-body', 'add image');

        $this->assertSame('images/demo.jpg', $content_['content']['path']);

        Http::assertSent(function (Request $request_): bool {
            return $request_->url() === 'https://github.example.test/repos/demo/repo/contents/images/demo.jpg'
                && $request_->method() === 'PUT'
                && str_contains((string) $request_->body(), base64_encode('image-body'));
        });
    }
}
