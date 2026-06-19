<?php

namespace L_Lib\Tests;

use L_Lib\Services\agents\skills\github\hahaha_service_agents_skills_github;
use L_Lib\Services\agents\skills\gmail\hahaha_service_agents_skills_gmail;
use L_Lib\Services\agents\skills\jira\hahaha_service_agents_skills_jira;
use L_Lib\Services\agents\skills\line\hahaha_service_agents_skills_line;
use L_Lib\Services\agents\skills\outlook\hahaha_service_agents_skills_outlook;
use L_Lib\Services\agents\skills\trello\hahaha_service_agents_skills_trello;
use Mockery;
use Tests\TestCase;

class hahaha_test_agents_skills_provider_command_Test extends TestCase
{
    public function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_trello_command_calls_board_dashboard_service_method(): void
    {
        $service_mock_ = Mockery::mock(hahaha_service_agents_skills_trello::class);
        $service_mock_->shouldReceive('Board_Dashboard_Get')
            ->once()
            ->with('board-1', [], [], [])
            ->andReturn(['board' => ['id' => 'board-1']]);

        $this->app->instance(hahaha_service_agents_skills_trello::class, $service_mock_);

        $this->artisan('l_lib:agents:skills:trello', [
            '--action' => 'board_dashboard_get',
            '--board-id' => 'board-1',
        ])->assertSuccessful();
    }

    public function test_jira_command_calls_issue_get_service_method(): void
    {
        $service_mock_ = Mockery::mock(hahaha_service_agents_skills_jira::class);
        $service_mock_->shouldReceive('Issue_Get')
            ->once()
            ->with('ABC-1', [])
            ->andReturn(['key' => 'ABC-1']);

        $this->app->instance(hahaha_service_agents_skills_jira::class, $service_mock_);

        $this->artisan('l_lib:agents:skills:jira', [
            '--action' => 'issue_get',
            '--issue-key' => 'ABC-1',
        ])->assertSuccessful();
    }

    public function test_github_command_calls_repo_get_service_method(): void
    {
        $service_mock_ = Mockery::mock(hahaha_service_agents_skills_github::class);
        $service_mock_->shouldReceive('Repo_Get')
            ->once()
            ->with('demo', 'repo', [])
            ->andReturn(['full_name' => 'demo/repo']);

        $this->app->instance(hahaha_service_agents_skills_github::class, $service_mock_);

        $this->artisan('l_lib:agents:skills:github', [
            '--action' => 'repo_get',
            '--owner' => 'demo',
            '--repo' => 'repo',
        ])->assertSuccessful();
    }

    public function test_outlook_command_calls_messages_get_service_method(): void
    {
        $service_mock_ = Mockery::mock(hahaha_service_agents_skills_outlook::class);
        $service_mock_->shouldReceive('Messages_Get')
            ->once()
            ->with('me', [])
            ->andReturn([['id' => 'message-1']]);

        $this->app->instance(hahaha_service_agents_skills_outlook::class, $service_mock_);

        $this->artisan('l_lib:agents:skills:outlook', [
            '--action' => 'messages_get',
        ])->assertSuccessful();
    }

    public function test_gmail_command_calls_labels_get_service_method(): void
    {
        $service_mock_ = Mockery::mock(hahaha_service_agents_skills_gmail::class);
        $service_mock_->shouldReceive('Labels_Get')
            ->once()
            ->with('me')
            ->andReturn([['id' => 'LABEL_1']]);

        $this->app->instance(hahaha_service_agents_skills_gmail::class, $service_mock_);

        $this->artisan('l_lib:agents:skills:gmail', [
            '--action' => 'labels_get',
        ])->assertSuccessful();
    }

    public function test_line_command_calls_bot_info_service_method(): void
    {
        $service_mock_ = Mockery::mock(hahaha_service_agents_skills_line::class);
        $service_mock_->shouldReceive('Bot_Info_Get')
            ->once()
            ->andReturn(['userId' => 'bot-1']);

        $this->app->instance(hahaha_service_agents_skills_line::class, $service_mock_);

        $this->artisan('l_lib:agents:skills:line', [
            '--action' => 'bot_info_get',
        ])->assertSuccessful();
    }
}
