<?php

namespace L_Lib\Console\Commands\git;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class hahaha_command_clone extends Command
{
    protected $signature = 'l_lib:git:clone
        {--url= : The repository url to clone}
        {--path= : The target path for the cloned repository}';

    protected $description = 'Clone the specified git repository into the target path';

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

        if (File::exists($target_path_)) {
            $this->components->error('Target directory already exists: '.$target_path_);

            return self::FAILURE;
        }

        $clone_result_ = Process::path(base_path())->run([
            'git',
            'clone',
            $repository_url_,
            $target_path_,
        ]);

        if ($clone_result_->failed()) {
            $this->components->error(trim($clone_result_->errorOutput()) ?: 'Git clone failed.');

            return self::FAILURE;
        }

        $this->components->info('Repository cloned to '.$target_path_);

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
