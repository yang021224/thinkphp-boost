<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Install\Agents;

/**
 * AI 编辑器代理抽象基类
 * 每个子类代表一种 AI 工具，知道如何检测自身是否安装、如何写 MCP 配置文件
 */
abstract class Agent
{
    /** 编辑器的唯一标识符（snake_case） */
    abstract public function name(): string;

    /** 在 UI 中显示的名称 */
    abstract public function displayName(): string;

    /** MCP 配置文件路径（相对于项目根目录） */
    abstract public function mcpConfigPath(): string;

    /**
     * 检测该编辑器是否安装在当前系统（通过命令行检测）
     */
    public function detectOnSystem(): bool
    {
        $command = $this->systemCommand();
        if ($command === null) {
            return false;
        }

        $result = shell_exec($command . ' 2>/dev/null');
        return $result !== null && trim($result) !== '';
    }

    /**
     * 检测当前项目中是否已有该编辑器的配置
     */
    public function detectInProject(string $rootPath): bool
    {
        foreach ($this->projectIndicators() as $indicator) {
            if (file_exists($rootPath . DIRECTORY_SEPARATOR . $indicator)
                || is_dir($rootPath . DIRECTORY_SEPARATOR . $indicator)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 写入 MCP 配置（JSON），如果文件已存在则合并 mcpServers 键，不覆盖已有配置
     */
    public function writeMcpConfig(string $rootPath): bool
    {
        $configPath = $rootPath . DIRECTORY_SEPARATOR . $this->mcpConfigPath();
        $dir        = dirname($configPath);

        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return false;
        }

        $existing = [];
        if (file_exists($configPath)) {
            $decoded = json_decode(file_get_contents($configPath), true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        $serverKey = 'thinkphp-boost';

        // 如果已存在此 server 配置则跳过
        if (isset($existing['mcpServers'][$serverKey])) {
            return true;
        }

        $existing['mcpServers'][$serverKey] = $this->mcpServerConfig();

        $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        return file_put_contents($configPath, $json) !== false;
    }

    /**
     * 构建 MCP server 配置块
     *
     * @return array<string, mixed>
     */
    protected function mcpServerConfig(): array
    {
        return [
            'command' => 'php',
            'args'    => ['think', 'boost:serve'],
        ];
    }

    /**
     * 用于系统级检测的 shell 命令（返回 null 表示不支持命令检测）
     */
    protected function systemCommand(): ?string
    {
        return null;
    }

    /**
     * 项目目录中可以判断该编辑器存在的文件/目录列表
     *
     * @return string[]
     */
    protected function projectIndicators(): array
    {
        return [];
    }
}
