<?php

namespace L_Lib\Services\agents\skills\common;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * 提供各外部服務共用的 Laravel HTTP client 建立流程。
 */
abstract class hahaha_service_agents_skills_http_base
{
    /**
     * 建立各 provider 共用的 HTTP client 基礎設定。
     *
     * @param array<string, mixed> $config_
     */
    public function Http_Client_Create(array $config): PendingRequest
    {
        $base_url_ = $this->Config_String_Get($config, 'base_url');
        $timeout_seconds_ = $this->Config_Int_Get($config, 'timeout', 10);
        $connect_timeout_seconds_ = $this->Config_Int_Get($config, 'connect_timeout', 3);

        return Http::baseUrl($base_url_)
            ->acceptJson()
            ->asJson()
            ->timeout($timeout_seconds_)
            ->connectTimeout($connect_timeout_seconds_)
            ->retry([200, 500, 1000], 0, function (Throwable $exception_): bool {
                if (! $exception_ instanceof RequestException) {
                    return true;
                }

                return $exception_->response->serverError();
            }, false);
    }

    /**
     * 讀取必要字串設定，並在值為空時直接中止。
     *
     * @param array<string, mixed> $config_
     */
    public function Config_String_Get(array $config, string $key, string $default = ''): string
    {
        $value_ = Arr::get($config, $key, $default);

        if (! is_string($value_)) {
            throw new RuntimeException("設定值 {$key} 必須為字串。");
        }

        $value_ = trim($value_);

        if ($value_ === '') {
            throw new RuntimeException("設定值 {$key} 不可為空。");
        }

        return $value_;
    }

    /**
     * 讀取整數設定，若為數字字串則自動轉整數。
     *
     * @param array<string, mixed> $config_
     */
    public function Config_Int_Get(array $config, string $key, int $default): int
    {
        $value_ = Arr::get($config, $key, $default);

        if (is_int($value_)) {
            return $value_;
        }

        if (is_numeric($value_)) {
            return (int) $value_;
        }

        return $default;
    }

    /**
     * 暫時覆蓋 base_url，讓同一 provider 可切換不同 API base path。
     *
     * @param array<string, mixed> $config_
     * @return array<string, mixed>
     */
    public function Config_Base_Url_Replace(array $config, string $base_url): array
    {
        $config['base_url'] = $base_url;

        return $config;
    }
}
