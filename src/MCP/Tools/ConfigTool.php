<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Tools;

use think\App;

/**
 * 配置读取工具 - 读取 ThinkPHP 配置值，支持点号路径
 */
class ConfigTool implements ToolInterface
{
    /**
     * 敏感字段关键词（字段名包含以下任一词时，值会被打码）
     */
    private const SENSITIVE_KEYWORDS = ['password', 'secret', 'key', 'token'];

    public function __construct(
        private readonly App $app
    ) {}

    public function getName(): string
    {
        return 'get_config';
    }

    public function getDescription(): string
    {
        return '读取 ThinkPHP 配置值，支持点号路径（如 database.connections.mysql）。敏感字段（password/secret/key/token）自动打码。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'key' => [
                    'type'        => 'string',
                    'description' => '配置键名，支持点号分隔的路径，例如 "database" 或 "database.default" 或 "database.connections.mysql"',
                ],
            ],
            'required' => ['key'],
        ];
    }

    public function execute(array $params): string
    {
        $key = trim((string)($params['key'] ?? ''));

        if ($key === '') {
            return '错误：key 参数不能为空。示例：database 或 database.default';
        }

        try {
            $value = $this->app->config->get($key);
        } catch (\Throwable $e) {
            return "读取配置时出错：{$e->getMessage()}";
        }

        if ($value === null) {
            // 再确认一下 key 是否真的不存在（has 方法）
            try {
                $exists = $this->app->config->has($key);
            } catch (\Throwable $e) {
                $exists = false;
            }

            if (!$exists) {
                return "配置键 \"{$key}\" 不存在。\n\n提示：可以先查询顶层键（如 \"database\"）了解结构，再用点号深入。";
            }
        }

        // 对敏感字段递归打码
        $masked = $this->maskSensitiveValues($value, $key);

        $output = "配置键：{$key}\n";
        $output .= str_repeat('-', 60) . "\n";

        if (is_array($masked)) {
            $output .= json_encode($masked, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } elseif ($masked === null) {
            $output .= 'null';
        } elseif (is_bool($masked)) {
            $output .= $masked ? 'true' : 'false';
        } else {
            $output .= (string)$masked;
        }

        $output .= "\n";

        return $output;
    }

    /**
     * 递归对敏感字段打码
     *
     * @param mixed  $value  配置值
     * @param string $key    当前字段名（用于判断顶层是否为敏感字段）
     * @return mixed
     */
    private function maskSensitiveValues(mixed $value, string $key): mixed
    {
        // 如果当前键名本身就是敏感字段，且值是标量，直接打码
        $bareKey = strtolower((string)$key);
        // 只取最后一段（点号路径的最后部分）
        if (str_contains($bareKey, '.')) {
            $segments = explode('.', $bareKey);
            $bareKey  = end($segments);
        }

        if ($this->isSensitiveKey($bareKey) && !is_array($value)) {
            return '***';
        }

        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $k => $v) {
            $fieldKey = strtolower((string)$k);
            if ($this->isSensitiveKey($fieldKey) && !is_array($v)) {
                $result[$k] = '***';
            } elseif (is_array($v)) {
                $result[$k] = $this->maskSensitiveValues($v, (string)$k);
            } else {
                $result[$k] = $v;
            }
        }

        return $result;
    }

    /**
     * 判断字段名是否包含敏感关键词
     */
    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYWORDS as $keyword) {
            if (str_contains($key, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
