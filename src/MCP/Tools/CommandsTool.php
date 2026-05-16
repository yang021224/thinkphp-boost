<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Tools;

use think\App;

/**
 * 命令工具 - 列出或执行 ThinkPHP think 命令
 */
class CommandsTool implements ToolInterface
{
    /**
     * 默认禁止执行的命令（安全限制）
     */
    private const DEFAULT_FORBIDDEN = [
        'serve',
        'clear',
        'optimize',
        'build',
        'boost:serve',
    ];

    public function __construct(
        private readonly App $app
    ) {}

    public function getName(): string
    {
        return 'run_think_command';
    }

    public function getDescription(): string
    {
        return '执行 ThinkPHP think 命令，如数据库迁移、生成代码等。部分危险命令被禁止。也可通过传入 command="list" 来列出所有可用命令。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'command' => [
                    'type'        => 'string',
                    'description' => '要执行的 think 命令名称，例如 "migrate"、"make:model"，传入 "list" 可列出所有命令',
                ],
                'args' => [
                    'type'        => 'array',
                    'description' => '命令的额外参数列表（可选）',
                    'items'       => ['type' => 'string'],
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $params): string
    {
        $command = trim((string)($params['command'] ?? ''));

        if ($command === '') {
            return "错误：command 参数不能为空。传入 \"list\" 可查看所有可用命令。";
        }

        if ($command === 'list') {
            return $this->listCommands();
        }

        return $this->runCommand($command, $params['args'] ?? []);
    }

    /**
     * 列出所有可用的 think 命令
     */
    private function listCommands(): string
    {
        $phpBinary = $this->getPhpBinary();
        $thinkScript = $this->getThinkScript();

        if (!is_file($thinkScript)) {
            return $this->listCommandsFromApp();
        }

        $escapedScript = escapeshellarg($thinkScript);
        $output = shell_exec("{$phpBinary} {$escapedScript} list 2>&1");

        if ($output === null || $output === '') {
            return $this->listCommandsFromApp();
        }

        return "ThinkPHP 可用命令列表：\n\n" . $output;
    }

    /**
     * 从 App 对象直接列出命令（当 think 脚本不可用时的回退方案）
     */
    private function listCommandsFromApp(): string
    {
        try {
            $console  = $this->app->console;
            $commands = $console->all();

            if (empty($commands)) {
                return "无法获取命令列表。";
            }

            $output = "ThinkPHP 可用命令列表：\n\n";
            $output .= sprintf("%-40s %s\n", '命令', '描述');
            $output .= str_repeat('-', 80) . "\n";

            ksort($commands);
            foreach ($commands as $name => $command) {
                try {
                    $instance    = is_string($command) ? new $command() : $command;
                    $description = method_exists($instance, 'getDescription')
                        ? $instance->getDescription()
                        : '';
                } catch (\Throwable $e) {
                    $description = '';
                }

                $output .= sprintf("%-40s %s\n", $name, $description);
            }

            return $output;
        } catch (\Throwable $e) {
            return "无法列出命令：{$e->getMessage()}";
        }
    }

    /**
     * 执行指定的 think 命令
     */
    private function runCommand(string $command, array $args = []): string
    {
        // 安全检查：禁止执行危险命令
        if ($this->isForbidden($command)) {
            $forbidden = implode('、', $this->getForbiddenList());
            return "安全限制：命令 \"{$command}\" 被禁止执行。\n禁止的命令：{$forbidden}";
        }

        $thinkScript = $this->getThinkScript();

        if (!is_file($thinkScript)) {
            return "错误：未找到 think 命令脚本，请确保在 ThinkPHP 项目根目录下运行。\n预期路径：{$thinkScript}";
        }

        $phpBinary      = $this->getPhpBinary();
        $escapedScript  = escapeshellarg($thinkScript);
        $escapedCommand = escapeshellarg($command);

        // 处理额外参数
        $escapedArgs = array_map('escapeshellarg', array_filter(
            (array)$args,
            fn($a) => is_string($a) || is_numeric($a)
        ));

        $argsString = empty($escapedArgs) ? '' : ' ' . implode(' ', $escapedArgs);
        $fullCommand = "{$phpBinary} {$escapedScript} {$escapedCommand}{$argsString} 2>&1";

        fwrite(STDERR, "CommandsTool: 执行命令: {$fullCommand}\n");

        $output     = '';
        $returnCode = 0;

        exec($fullCommand, $outputLines, $returnCode);
        $output = implode("\n", $outputLines);

        $status = $returnCode === 0 ? '成功' : "失败（退出码：{$returnCode}）";

        return "命令: php think {$command}" . ($argsString ? ' ' . trim($argsString) : '') . "\n"
            . "状态: {$status}\n"
            . str_repeat('-', 60) . "\n"
            . ($output ?: '(无输出)');
    }

    /**
     * 检查命令是否在禁止列表中
     */
    private function isForbidden(string $command): bool
    {
        $forbidden = $this->getForbiddenList();

        // 精确匹配和前缀匹配
        foreach ($forbidden as $item) {
            if ($command === $item || str_starts_with($command, $item . ':') || str_starts_with($command, $item . ' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取禁止命令列表（合并配置和默认值）
     */
    private function getForbiddenList(): array
    {
        $configured = [];

        try {
            $configured = (array)$this->app->config->get('boost.commands.forbidden', []);
        } catch (\Throwable $e) {
            // 忽略配置读取错误
        }

        return array_unique(array_merge(self::DEFAULT_FORBIDDEN, $configured));
    }

    /**
     * 获取 PHP 可执行文件路径
     */
    private function getPhpBinary(): string
    {
        return PHP_BINARY ?: 'php';
    }

    /**
     * 获取 think 命令脚本路径
     */
    private function getThinkScript(): string
    {
        return $this->app->getRootPath() . 'think';
    }
}
