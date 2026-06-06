<?php

namespace L_Lib\Console\Commands\git;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class hahaha_command_pull extends Command
{
    protected $signature = 'l_lib:git:pull
        {--url= : The repository url to pull}
        {--path= : The target path for the repository}';

    protected $description = 'Pull the specified git repository into the target path';

    public function handle(): int
    {
        $repository_url_ = trim((string) $this->option('url'));
        $target_path_input_ = trim((string) $this->option('path'));

        if ($repository_url_ === '') {
            $this->components->error('The --url option is required.');

            return self::FAILURE;
        }

        if ($target_path_input_ === '') {
            $this->components->error('The --path option is required.');

            return self::FAILURE;
        }

        $target_path_ = $this->target_path_resolve_($target_path_input_);

        if (! File::isDirectory($target_path_)) {
            $this->components->error('Target directory does not exist: '.$target_path_);

            return self::FAILURE;
        }

        $pull_result_ = Process::path($target_path_)->run([
            'git',
            'pull',
            $repository_url_,
        ]);

        if ($pull_result_->failed()) {
            $this->components->error(trim($pull_result_->errorOutput()) ?: 'Git pull failed.');

            return self::FAILURE;
        }

        $this->components->info('Repository pulled in '.$target_path_);

        return self::SUCCESS;
    }

    private function target_path_resolve_(string $target_path_input_): string
    {
        if ($this->target_path_is_absolute_($target_path_input_)) {
            return $target_path_input_;
        }

        return base_path($target_path_input_);
    }

    private function target_path_is_absolute_(string $target_path_input_): bool
    {
        if ($target_path_input_ === '') {
            return false;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $target_path_input_) === 1) {
            return true;
        }

        return str_starts_with($target_path_input_, '/')
            || str_starts_with($target_path_input_, '\\');
    }
}
