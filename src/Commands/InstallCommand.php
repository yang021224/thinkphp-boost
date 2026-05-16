<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Commands;

use think\console\Command;
use think\console\Input;
use think\console\Output;

class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('boost:install')
            ->setDescription('Install and configure ThinkPHP Boost MCP Server');
    }

    protected function execute(Input $input, Output $output): int
    {
        $output->writeln('');
        $output->writeln('<info>╔══════════════════════════════════════╗</info>');
        $output->writeln('<info>║      ThinkPHP Boost 安装向导         ║</info>');
        $output->writeln('<info>╚══════════════════════════════════════╝</info>');
        $output->writeln('');

        // ── 1. 选择启用哪些工具 ──────────────────────────────────────
        $output->writeln('<comment>请选择要启用的 MCP 工具：</comment>');
        $output->writeln('');

        $tools = [
            'app_info'           => $output->confirm($input, '  启用应用信息工具          (get_app_info)?', true),
            'routes'             => $output->confirm($input, '  启用路由列表工具          (get_routes)?', true),
            'schema'             => $output->confirm($input, '  启用数据库结构工具        (get_database_schema)?', true),
            'db_connections'     => $output->confirm($input, '  启用数据库连接检查工具    (database_connections)?', true),
            'commands'           => $output->confirm($input, '  启用命令执行工具          (run_think_command)?', true),
            'logs'               => $output->confirm($input, '  启用日志查看工具          (get_logs)?', true),
            'last_error'         => $output->confirm($input, '  启用最近错误工具          (get_last_error)?', true),
            'config'             => $output->confirm($input, '  启用配置读取工具          (get_config)?', true),
            'execute_sql'        => $output->confirm($input, '  启用 SQL 查询工具         (execute_sql)?', true),
            'run_php'            => $output->confirm($input, '  启用 PHP 执行工具         (run_php)?', true),
            'format_code'        => $output->confirm($input, '  启用代码格式化工具        (format_code)?', true),
            'get_absolute_url'   => $output->confirm($input, '  启用 URL 解析工具         (get_absolute_url)?', true),
        ];

        $output->writeln('');

        // ── 2. 选择 AI 工具 ──────────────────────────────────────────
        $aiTool = $output->choice(
            $input,
            '你使用哪个 AI 工具？',
            [
                'Claude Code',
                'Cursor',
                'Windsurf',
                'GitHub Copilot (VS Code)',
                'Gemini CLI',
                '跳过（手动配置）',
            ],
            0
        );

        $output->writeln('');

        // ── 3. 发布配置文件 ──────────────────────────────────────────
        $this->publishConfig($tools, $output);

        // ── 4. 生成 MCP 配置文件 ─────────────────────────────────────
        if ($aiTool !== '跳过（手动配置）') {
            $this->generateMcpConfig($aiTool, $output);
        }

        // ── 5. 完成提示 ───────────────────────────────────────────────
        $output->writeln('');
        $output->writeln('<info>✓ 安装完成！</info>');
        $output->writeln('');
        $output->writeln('下一步：');
        $output->writeln('  1. 重启你的 AI 工具以加载 MCP 配置');
        $output->writeln('  2. 在 AI 工具中验证连接：运行 <comment>get_app_info</comment> 工具');
        $output->writeln('');
        $output->writeln('如需调试，启动时加上 <comment>--debug</comment> 参数：');
        $output->writeln('  <comment>php think boost:serve --debug</comment>');
        $output->writeln('');

        return 0;
    }

    /**
     * @param array<string, bool> $tools
     */
    private function publishConfig(array $tools, Output $output): void
    {
        $configPath = $this->app->getConfigPath() . 'boost.php';

        if (file_exists($configPath)) {
            $output->writeln("  <comment>⚠ 配置文件已存在，跳过：{$configPath}</comment>");
            return;
        }

        $bool        = fn(bool $v): string => $v ? 'true' : 'false';
        $toolsLines  = '';
        $toolLabels  = [
            'app_info'         => 'app_info',
            'routes'           => 'routes',
            'schema'           => 'schema',
            'db_connections'   => 'db_connections',
            'commands'         => 'commands',
            'logs'             => 'logs',
            'last_error'       => 'last_error',
            'config'           => 'config',
            'execute_sql'      => 'execute_sql',
            'run_php'          => 'run_php',
            'format_code'      => 'format_code',
            'get_absolute_url' => 'get_absolute_url',
        ];

        foreach ($toolLabels as $key => $_) {
            $val         = $bool($tools[$key] ?? true);
            $toolsLines .= "        '{$key}' => {$val},\n";
        }

        $content = <<<PHP
        <?php

        return [
            'tools' => [
        {$toolsLines}    ],

            'logs' => [
                'path'      => runtime_path('log'),
                'max_lines' => 500,
            ],

            'commands' => [
                'forbidden' => ['serve', 'clear', 'optimize', 'build', 'boost:serve'],
            ],
        ];
        PHP;

        if (file_put_contents($configPath, $content) !== false) {
            $output->writeln("  <info>✓ 配置文件已生成：{$configPath}</info>");
        } else {
            $output->writeln("  <error>✗ 配置文件生成失败，请检查目录权限：{$configPath}</error>");
        }
    }

    private function generateMcpConfig(string $aiTool, Output $output): void
    {
        $rootPath = $this->app->getRootPath();

        $mcpJson = json_encode([
            'mcpServers' => [
                'thinkphp-boost' => [
                    'command' => 'php',
                    'args'    => ['think', 'boost:serve'],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $targetFile = match ($aiTool) {
            'Claude Code'             => $rootPath . '.mcp.json',
            'Cursor'                  => $rootPath . '.cursor/mcp.json',
            'Windsurf'                => $rootPath . '.windsurf/mcp_config.json',
            'GitHub Copilot (VS Code)' => $rootPath . '.vscode/mcp.json',
            'Gemini CLI'              => $rootPath . '.gemini/mcp.json',
            default                   => null,
        };

        if ($targetFile === null) {
            return;
        }

        $targetDir = dirname($targetFile);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        if (file_exists($targetFile)) {
            $output->writeln("  <comment>⚠ MCP 配置文件已存在，跳过：{$targetFile}</comment>");
            return;
        }

        if (file_put_contents($targetFile, $mcpJson) !== false) {
            $output->writeln("  <info>✓ MCP 配置文件已生成：{$targetFile}</info>");
        } else {
            $output->writeln("  <error>✗ MCP 配置文件生成失败：{$targetFile}</error>");
        }

        // 针对不同工具输出额外提示
        match ($aiTool) {
            'GitHub Copilot (VS Code)' => $output->writeln("  <comment>提示：VS Code 需要安装 GitHub Copilot 扩展并启用 MCP 功能。</comment>"),
            'Gemini CLI'               => $output->writeln("  <comment>提示：Gemini CLI 需要 gemini 命令行工具已安装并登录。</comment>"),
            default                    => null,
        };
    }
}
