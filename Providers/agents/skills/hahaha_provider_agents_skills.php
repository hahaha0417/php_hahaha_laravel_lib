<?php

namespace L_Lib\Providers\agents\skills;

use L_Lib\Console\Commands\agents\skills\github\hahaha_command_agents_skills_github;
use L_Lib\Console\Commands\agents\skills\gmail\hahaha_command_agents_skills_gmail;
use L_Lib\Console\Commands\agents\skills\jira\hahaha_command_agents_skills_jira;
use L_Lib\Console\Commands\agents\skills\line\hahaha_command_agents_skills_line;
use L_Lib\Console\Commands\agents\skills\outlook\hahaha_command_agents_skills_outlook;
use L_Lib\Console\Commands\agents\skills\trello\hahaha_command_agents_skills_trello;

/**
 * 集中整理 agents skills command 的註冊清單。
 */
class hahaha_provider_agents_skills
{
    /**
     * 回傳 skills 領域需要註冊的 artisan commands。
     *
     * @return array<int, class-string>
     */
    public static function Commands_Resolve(): array
    {
        return [
            hahaha_command_agents_skills_trello::class,
            hahaha_command_agents_skills_jira::class,
            hahaha_command_agents_skills_github::class,
            hahaha_command_agents_skills_outlook::class,
            hahaha_command_agents_skills_gmail::class,
            hahaha_command_agents_skills_line::class,
        ];
    }
}
