<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Tools;

use think\App;

/**
 * URL 解析工具 - 将路由名或相对 URI 转换为完整的绝对 URL
 */
class GetAbsoluteUrlTool implements ToolInterface
{
    public function __construct(
        private readonly App $app
    ) {}

    public function getName(): string
    {
        return 'get_absolute_url';
    }

    public function getDescription(): string
    {
        return '将 ThinkPHP 路由名称或相对 URI 转换为完整的绝对 URL。支持路由别名、控制器@方法格式，以及普通相对路径。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'uri' => [
                    'type'        => 'string',
                    'description' => '要转换的路由名称、控制器@方法（如 "index/index@index"）或相对路径（如 "/api/users"）。',
                ],
                'params' => [
                    'type'        => 'object',
                    'description' => '路由参数（可选），键值对格式，如 {"id": 1}。',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'domain' => [
                    'type'        => 'string',
                    'description' => '自定义域名（可选），不填则使用应用配置中的 app_host 或 HTTP_HOST。',
                ],
            ],
            'required' => ['uri'],
        ];
    }

    public function execute(array $params): string
    {
        $uri         = trim((string)($params['uri'] ?? ''));
        $routeParams = (array)($params['params'] ?? []);
        $domain      = isset($params['domain']) ? trim((string)$params['domain']) : null;

        if ($uri === '') {
            return "错误：uri 参数不能为空。";
        }

        $baseUrl = $this->resolveBaseUrl($domain);

        // 先尝试 ThinkPHP url() 助手函数
        $resolved = $this->tryThinkUrl($uri, $routeParams);
        if ($resolved !== null) {
            // 如果 url() 已返回完整 URL，直接使用
            if (str_starts_with($resolved, 'http://') || str_starts_with($resolved, 'https://')) {
                return $this->formatResult($uri, $resolved, $routeParams);
            }
            // 否则拼接 base URL
            $absolute = rtrim($baseUrl, '/') . '/' . ltrim($resolved, '/');
            return $this->formatResult($uri, $absolute, $routeParams);
        }

        // 降级：直接拼接相对路径
        $absolute = rtrim($baseUrl, '/') . '/' . ltrim($uri, '/');
        return $this->formatResult($uri, $absolute, $routeParams);
    }

    private function tryThinkUrl(string $uri, array $routeParams): ?string
    {
        try {
            if (function_exists('url')) {
                $result = url($uri, $routeParams);
                // ThinkPHP url() 返回 Url 对象或字符串
                return (string)$result;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function resolveBaseUrl(?string $domain): string
    {
        if ($domain !== null && $domain !== '') {
            return str_starts_with($domain, 'http') ? $domain : 'http://' . $domain;
        }

        try {
            $appHost = $this->app->config->get('app.app_host', '');
            if ($appHost !== '' && $appHost !== null) {
                return rtrim((string)$appHost, '/');
            }
        } catch (\Throwable) {
        }

        // 尝试从 .env 读取
        $envHost = $this->getEnvVar('APP_URL') ?? $this->getEnvVar('APP_HOST');
        if ($envHost !== null && $envHost !== '') {
            return rtrim($envHost, '/');
        }

        return 'http://localhost';
    }

    private function getEnvVar(string $key): ?string
    {
        try {
            $envFile = $this->app->getRootPath() . '.env';
            if (!is_file($envFile)) {
                return null;
            }

            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                return null;
            }

            foreach ($lines as $line) {
                $line = trim($line);
                if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$envKey, $envVal] = explode('=', $line, 2);
                if (trim($envKey) === $key) {
                    return trim($envVal, " \t\n\r\0\x0B\"'");
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function formatResult(string $input, string $absoluteUrl, array $params): string
    {
        $output  = "输入：{$input}\n";
        if (!empty($params)) {
            $output .= "参数：" . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n";
        }
        $output .= "完整 URL：{$absoluteUrl}\n";
        return $output;
    }
}
