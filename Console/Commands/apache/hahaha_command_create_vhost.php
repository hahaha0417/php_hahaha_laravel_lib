<?php

namespace L_Lib\Console\Commands\apache;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class hahaha_command_create_vhost extends Command
{
    public $signature = 'l_lib:apache:create_vhost
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

    public $description = 'Append an Apache VirtualHost block into the configured httpd-vhosts.conf file';

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

        if (! $this->Port_Is_Valid($port_)) {
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
        $has_existing_vhost_ = $this->Port_Exists_In_Vhosts_Content(
            vhosts_content_: $vhosts_content_,
            port_: $port_,
        );

        if ($has_existing_vhost_ && ! $this->Overwrite_Should_Continue($port_, $force_option_)) {
            return self::FAILURE;
        }

        if ($has_existing_vhost_) {
            $vhosts_content_ = $this->Existing_Vhost_Remove(
                vhosts_content_: $vhosts_content_,
                port_: $port_,
            );
        }

        $vhost_block_ = $this->Vhost_Block_Build(
            port_: $port_,
            document_root_: $document_root_,
            error_log_: $error_log_,
            custom_log_: $custom_log_,
            directory_: $directory_,
            server_admin_: $server_admin_,
            server_name_: $server_name_,
            server_alias_: $server_alias_,
        );

        $content_to_write_ = $this->Content_With_Vhost_Block_Build(
            vhosts_content_: $vhosts_content_,
            vhost_block_: $vhost_block_,
        );

        File::put($vhosts_path_, $content_to_write_);

        $this->components->info(
            ($has_existing_vhost_ ? 'VirtualHost overwritten in ' : 'VirtualHost appended to ').$vhosts_path_,
        );

        return self::SUCCESS;
    }

    public function Port_Is_Valid(string $port): bool
    {
        if ($port === '' || ! ctype_digit($port)) {
            return false;
        }

        $port_number_ = (int) $port;

        return $port_number_ >= 1 && $port_number_ <= 65535;
    }

    public function Overwrite_Should_Continue(string $port, string $force_option): bool
    {
        if ($force_option === '1') {
            return true;
        }

        if (! $this->confirm('VirtualHost already exists for port ['.$port.']. Do you want to overwrite it?', false)) {
            $this->components->info('VirtualHost overwrite cancelled.');

            return false;
        }

        return true;
    }

    public function Port_Exists_In_Vhosts_Content(string $vhosts_content, string $port): bool
    {
        return preg_match($this->Listen_Pattern_Build($port), $vhosts_content) === 1
            || preg_match($this->Virtual_Host_Pattern_Build($port), $vhosts_content) === 1;
    }

    public function Existing_Vhost_Remove(string $vhosts_content, string $port): string
    {
        $content_without_listen_ = preg_replace(
            $this->Listen_Pattern_Build($port),
            '',
            $vhosts_content,
        );

        $content_without_vhost_ = preg_replace(
            $this->Virtual_Host_Pattern_Build($port),
            '',
            $content_without_listen_ ?? $vhosts_content,
        );

        if (! is_string($content_without_vhost_)) {
            return $vhosts_content;
        }

        return trim($content_without_vhost_);
    }

    public function Content_With_Vhost_Block_Build(string $vhosts_content, string $vhost_block): string
    {
        if ($vhosts_content === '') {
            return $vhost_block;
        }

        return rtrim($vhosts_content).PHP_EOL.PHP_EOL.$vhost_block;
    }

    public function Listen_Pattern_Build(string $port): string
    {
        return '/^[ \t]*Listen[ \t]+'.preg_quote($port, '/').'[ \t]*\R?/mi';
    }

    public function Virtual_Host_Pattern_Build(string $port): string
    {
        return '/^[ \t]*<VirtualHost \*:'.preg_quote($port, '/').'>\R.*?^[ \t]*<\/VirtualHost>[ \t]*\R?/mis';
    }

    public function Vhost_Block_Build(
        string $port,
        string $document_root,
        string $error_log,
        string $custom_log,
        string $directory,
        string $server_admin,
        string $server_name,
        string $server_alias,
    ): string {
        return <<<VHOST
Listen {$port}
<VirtualHost *:{$port}>
    ServerAdmin {$server_admin}
\tDocumentRoot "{$document_root}"
    ServerName {$server_name}
    ServerAlias {$server_alias}
    ErrorLog "{$error_log}"
    CustomLog "{$custom_log}" common
\t<Directory "{$directory}">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

</VirtualHost>
VHOST;
    }
}
