<?php

namespace L_Lib\Console\Commands\agents\skills\common;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;

/**
 * 提供各 provider command 共用的 option 解析與 JSON 輸出。
 */
abstract class hahaha_command_agents_skills_base extends Command
{
    /**
     * 解析 JSON option，回傳 array 結構。
     *
     * @return array<string, mixed>
     */
    public function Json_Option_Resolve(string $option_name): array
    {
        $json_input_ = trim((string) $this->option($option_name));

        if ($json_input_ === '') {
            return [];
        }

        try {
            $decoded_value_ = json_decode($json_input_, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception_) {
            throw new InvalidArgumentException("The --{$option_name} option must be valid JSON.", previous: $exception_);
        }

        if (! is_array($decoded_value_)) {
            throw new InvalidArgumentException("The --{$option_name} option must decode to a JSON object or array.");
        }

        return $decoded_value_;
    }

    /**
     * 讀取必填 option，若為空直接丟錯。
     */
    public function Required_Option_Resolve(string $option_name): string
    {
        $option_value_ = trim((string) $this->option($option_name));

        if ($option_value_ === '') {
            throw new InvalidArgumentException("The --{$option_name} option is required.");
        }

        return $option_value_;
    }

    /**
     * 讀取非必填 option。
     */
    public function Optional_Option_Resolve(string $option_name): string
    {
        return trim((string) $this->option($option_name));
    }

    /**
     * 讀取檔案內容，常用於圖片上傳或 raw mail 組裝。
     */
    public function File_Content_Resolve(string $option_name): string
    {
        $file_path_input_ = $this->Required_Option_Resolve($option_name);
        $file_path_ = $this->Path_Absolute_Resolve($file_path_input_);

        if (! File::isFile($file_path_)) {
            throw new InvalidArgumentException("File does not exist: {$file_path_}");
        }

        return (string) File::get($file_path_);
    }

    /**
     * 以 JSON pretty print 輸出 command 結果。
     *
     * @param array<string, mixed> $result_
     */
    public function Result_Output(array $result): void
    {
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 將相對路徑轉成專案絕對路徑。
     */
    public function Path_Absolute_Resolve(string $path_input): string
    {
        if ($this->Path_Is_Absolute($path_input)) {
            return $path_input;
        }

        return base_path(str_replace('/', DIRECTORY_SEPARATOR, $path_input));
    }

    /**
     * 判斷路徑是否已為絕對路徑。
     */
    public function Path_Is_Absolute(string $path_input): bool
    {
        if ($path_input === '') {
            return false;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path_input) === 1) {
            return true;
        }

        return str_starts_with($path_input, '/')
            || str_starts_with($path_input, '\\');
    }
}
