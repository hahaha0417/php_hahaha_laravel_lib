<?php

namespace L_Lib\Console\Commands\apache;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class hahaha_command_create_vhost extends Command
{
    protected $signature = 'l_lib:apache:create_vhost
        {--port= : 指定 Listen 與 VirtualHost port}
        {--document_root= : 指定 DocumentRoot}
        {--error_log= : 指定 ErrorLog}
        {--custom_log= : 指定 CustomLog}
        {--directory= : 指定 Directory path}
        {--force=2 : 1 強制覆寫既有 port，2 需要確認}
        {--vhosts_path=C:/web/xampp/apache/conf/extra/httpd-vhosts.conf : 指定 Apache vhosts 設定檔}
        {--server_admin=webmaster@dummy-host.example.com : 指定 ServerAdmin}
        {--server_name=dummy-host.example.com : 指定 ServerName}
        {--server_alias=www.dummy-host.example.com : 指定 ServerAlias}';

    protected $description = 'Append an Apache VirtualHost block into the configured httpd-vhosts.conf file';

    public function handle(): int
    {
        $port_ = trim((string) $this->option('port'));
        $document_root_ = trim((string) $this->option('document_root'));
        $error_log_ = trim((string) $this->option('error_log'));
        $custom_log_ = trim((string) $this->option('custom_log'));
        $directory_ = trim((string) $this->option('directory'));
        $force_option_ = trim((string) $this->option('force'));
        $vhosts_path_ = trim((string) $this->option('vhosts_path'));
        $server_admin_ = trim((string) $this->option('server_admin'));
        $server_name_ = trim((string) $this->option('server_name'));
        $server_alias_ = trim((string) $this->option('server_alias'));

        if (! $this->port_is_valid_($port_)) {
            $this->components->error('The --port option must be an integer between 1 and 65535.');

            return self::FAILURE;
        }

        if (! in_array($force_option_, ['1', '2'], true)) {
            $this->components->error('The --force option must be 1 or 2.');

            return self::FAILURE;
        }

        foreach ([
            '--document_root' => $document_root_,
            '--error_log' => $error_log_,
            '--custom_log' => $custom_log_,
            '--directory' => $directory_,
            '--vhosts_path' => $vhosts_path_,
            '--server_admin' => $server_admin_,
            '--server_name' => $server_name_,
            '--server_alias' => $server_alias_,
        ] as $option_name_ => $option_value_) {
            if ($option_value_ === '') {
                $this->components->error($option_name_.' 不可為空。');

                return self::FAILURE;
            }
        }

        if (! File::exists($vhosts_path_)) {
            $this->components->error('Vhosts file does not exist: '.$vhosts_path_);

            return self::FAILURE;
        }

        $vhosts_content_ = File::get($vhosts_path_);
        $has_existing_vhost_ = $this->port_exists_in_vhosts_content_(
            vhosts_content_: $vhosts_content_,
            port_: $port_,
        );

        if ($has_existing_vhost_ && ! $this->overwrite_should_continue_($port_, $force_option_)) {
            return self::FAILURE;
        }

        if ($has_existing_vhost_) {
            $vhosts_content_ = $this->existing_vhost_remove_(
                vhosts_content_: $vhosts_content_,
                port_: $port_,
            );
        }

        $vhost_block_ = $this->vhost_block_build_(
            port_: $port_,
            document_root_: $document_root_,
            error_log_: $error_log_,
            custom_log_: $custom_log_,
            directory_: $directory_,
            server_admin_: $server_admin_,
            server_name_: $server_name_,
            server_alias_: $server_alias_,
        );

        $content_to_write_ = $this->content_with_vhost_block_build_(
            vhosts_content_: $vhosts_content_,
            vhost_block_: $vhost_block_,
        );

        File::put($vhosts_path_, $content_to_write_);

        $this->components->info(
            ($has_existing_vhost_ ? 'VirtualHost overwritten in ' : 'VirtualHost appended to ').$vhosts_path_,
        );

        return self::SUCCESS;
    }

    private function port_is_valid_(string $port_): bool
    {
        if ($port_ === '' || ! ctype_digit($port_)) {
            return false;
        }

        $port_number_ = (int) $port_;

        return $port_number_ >= 1 && $port_number_ <= 65535;
    }

    private function overwrite_should_continue_(string $port_, string $force_option_): bool
    {
        if ($force_option_ === '1') {
            return true;
        }

        if (! $this->confirm('VirtualHost already exists for port ['.$port_.']. Do you want to overwrite it?', false)) {
            $this->components->info('VirtualHost overwrite cancelled.');

            return false;
        }

        return true;
    }

    private function port_exists_in_vhosts_content_(string $vhosts_content_, string $port_): bool
    {
        return preg_match($this->listen_pattern_build_($port_), $vhosts_content_) === 1
            || preg_match($this->virtual_host_pattern_build_($port_), $vhosts_content_) === 1;
    }

    private function existing_vhost_remove_(string $vhosts_content_, string $port_): string
    {
        $content_without_listen_ = preg_replace(
            $this->listen_pattern_build_($port_),
            '',
            $vhosts_content_,
        );

        $content_without_vhost_ = preg_replace(
            $this->virtual_host_pattern_build_($port_),
            '',
            $content_without_listen_ ?? $vhosts_content_,
        );

        if (! is_string($content_without_vhost_)) {
            return $vhosts_content_;
        }

        return trim($content_without_vhost_);
    }

    private function content_with_vhost_block_build_(string $vhosts_content_, string $vhost_block_): string
    {
        if ($vhosts_content_ === '') {
            return $vhost_block_;
        }

        return rtrim($vhosts_content_).PHP_EOL.PHP_EOL.$vhost_block_;
    }

    private function listen_pattern_build_(string $port_): string
    {
        return '/^[ \t]*Listen[ \t]+'.preg_quote($port_, '/').'[ \t]*\R?/mi';
    }

    private function virtual_host_pattern_build_(string $port_): string
    {
        return '/^[ \t]*<VirtualHost \*:'.preg_quote($port_, '/').'>\R.*?^[ \t]*<\/VirtualHost>[ \t]*\R?/mis';
    }

    private function vhost_block_build_(
        string $port_,
        string $document_root_,
        string $error_log_,
        string $custom_log_,
        string $directory_,
        string $server_admin_,
        string $server_name_,
        string $server_alias_,
    ): string {
        return <<<VHOST
Listen {$port_}
<VirtualHost *:{$port_}>
    ServerAdmin {$server_admin_}
\tDocumentRoot "{$document_root_}"
    ServerName {$server_name_}
    ServerAlias {$server_alias_}
    ErrorLog "{$error_log_}"
    CustomLog "{$custom_log_}" common
\t<Directory "{$directory_}">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

</VirtualHost>
VHOST;
    }
}
