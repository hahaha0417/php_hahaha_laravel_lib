<?php

namespace L_Lib\Console\Commands\apache;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class hahaha_command_create_proxy_vhost extends Command
{
    public $signature = 'l_lib:apache:create_proxy_vhost
        {--port= : 指定 Listen 與 VirtualHost port}
        {--error_log= : 指定 ErrorLog}
        {--custom_log= : 指定 CustomLog}
        {--proxy_pass= : 指定 ProxyPass URL}
        {--proxy_pass_reverse= : 指定 ProxyPassReverse URL}
        {--force=2 : 1 強制覆寫既有 port，2 需要確認}
        {--vhosts_path=C:/web/xampp/apache/conf/extra/httpd-vhosts.conf : 指定 Apache vhosts 設定檔}
        {--server_name=localhost : 指定 ServerName}';

    public $description = 'Append an Apache proxy VirtualHost block into the configured httpd-vhosts.conf file';

    public function handle(): int
    {
        $port_ = trim((string) $this->option('port'));
        $error_log_ = trim((string) $this->option('error_log'));
        $custom_log_ = trim((string) $this->option('custom_log'));
        $proxy_pass_ = trim((string) $this->option('proxy_pass'));
        $proxy_pass_reverse_ = trim((string) $this->option('proxy_pass_reverse'));
        $force_option_ = trim((string) $this->option('force'));
        $vhosts_path_ = trim((string) $this->option('vhosts_path'));
        $server_name_ = trim((string) $this->option('server_name'));

        if (! $this->Port_Is_Valid($port_)) {
            $this->components->error('The --port option must be an integer between 1 and 65535.');

            return self::FAILURE;
        }

        if (! in_array($force_option_, ['1', '2'], true)) {
            $this->components->error('The --force option must be 1 or 2.');

            return self::FAILURE;
        }

        foreach ([
            '--error_log' => $error_log_,
            '--custom_log' => $custom_log_,
            '--proxy_pass' => $proxy_pass_,
            '--proxy_pass_reverse' => $proxy_pass_reverse_,
            '--vhosts_path' => $vhosts_path_,
            '--server_name' => $server_name_,
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
            error_log_: $error_log_,
            custom_log_: $custom_log_,
            proxy_pass_: $proxy_pass_,
            proxy_pass_reverse_: $proxy_pass_reverse_,
            server_name_: $server_name_,
        );

        $content_to_write_ = $this->Content_With_Vhost_Block_Build(
            vhosts_content_: $vhosts_content_,
            vhost_block_: $vhost_block_,
        );

        File::put($vhosts_path_, $content_to_write_);

        $this->components->info(
            ($has_existing_vhost_ ? 'Proxy VirtualHost overwritten in ' : 'Proxy VirtualHost appended to ').$vhosts_path_,
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
        string $error_log,
        string $custom_log,
        string $proxy_pass,
        string $proxy_pass_reverse,
        string $server_name,
    ): string {
        return <<<VHOST
Listen {$port}
<VirtualHost *:{$port}>
    ServerName {$server_name} 


    # 轉發 Header 給後端 Kestrel
    RequestHeader set X-Forwarded-For "%{REMOTE_ADDR}s"
    RequestHeader set X-Forwarded-Proto expr=%{REQUEST_SCHEME}

    ProxyPass / {$proxy_pass}
    ProxyPassReverse / {$proxy_pass_reverse}

    ErrorLog "{$error_log}"
    CustomLog "{$custom_log}" common
\t

</VirtualHost>
VHOST;
    }
}
