<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Tools;

use think\App;

/**
 * 在 ThinkPHP 应用上下文中执行任意 PHP 代码
 *
 * 通过子进程执行，避免 fatal error 崩溃 MCP Server。
 * 子进程会完整引导 ThinkPHP，因此可以访问模型、数据库、配置等。
 */
class RunPhpTool implements ToolInterface
{
    public function __construct(
        private readonly App $app
    ) {}

    public function getName(): string
    {
        return 'run_php';
    }

    public function getDescription(): string
    {
        return '在 ThinkPHP 应用上下文中执行任意 PHP 代码。可以访问所有模型、Db 类、配置、助手函数等。无需 <?php 标签。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'code' => [
                    'type'        => 'string',
                    'description' => '要执行的 PHP 代码，无需 <?php 标签。用 var_dump()、print_r() 或 echo 输出结果。',
                ],
            ],
            'required' => ['code'],
        ];
    }

    public function execute(array $params): string
    {
        $code = trim($params['code'] ?? '');

        if ($code === '') {
            return '错误：code 参数不能为空。';
        }

        $rootPath   = $this->app->getRootPath();
        $autoload   = $rootPath . 'vendor/autoload.php';
        $thinkEntry = $rootPath . 'think';

        if (!file_exists($autoload)) {
            return "错误：找不到 {$autoload}，请确认项目已执行 composer install。";
        }

        $tempFile = $this->writeTempScript($rootPath, $autoload, $code);

        if ($tempFile === null) {
            return '错误：无法创建临时脚本文件，请检查 runtime/ 目录权限。';
        }

        try {
            return $this->runScript($tempFile);
        } finally {
            @unlink($tempFile);
        }
    }

    private function writeTempScript(string $rootPath, string $autoload, string $code): ?string
    {
        $runtimePath = $this->app->getRuntimePath();
        if (!is_dir($runtimePath)) {
            @mkdir($runtimePath, 0755, true);
        }

        $tempFile = $runtimePath . 'boost_php_' . bin2hex(random_bytes(8)) . '.php';

        // 去掉用户代码中的 <?php 标签（如果有）
        $code = preg_replace('/^\s*<\?php\s*/i', '', $code);

        $escapedRoot = addslashes($rootPath);
        $script      = <<<PHP
        <?php
        // 引导 ThinkPHP 应用
        define('ROOT_PATH', '{$escapedRoot}');

        require '{$autoload}';

        \$app = new \\think\\App('{$escapedRoot}');
        \$http = \$app->http;

        // 初始化应用（不走路由和控制器，仅加载服务）
        \$app->initialize();

        // 注入常用别名方便使用
        use \\think\\facade\\Db;
        use \\think\\facade\\Cache;
        use \\think\\facade\\Config;

        // 执行用户代码
        ob_start();
        try {
            {$code}
        } catch (\\Throwable \$e) {
            echo "\\n[异常] " . get_class(\$e) . ": " . \$e->getMessage();
            echo "\\n  在 " . \$e->getFile() . ":" . \$e->getLine();
        }
        echo ob_get_clean();
        PHP;

        if (file_put_contents($tempFile, $script) === false) {
            return null;
        }

        return $tempFile;
    }

    private function runScript(string $tempFile): string
    {
        $phpBin  = PHP_BINARY;
        $command = escapeshellarg($phpBin) . ' ' . escapeshellarg($tempFile) . ' 2>&1';

        $output    = [];
        $exitCode  = 0;
        exec($command, $output, $exitCode);

        $result = implode("\n", $output);

        if ($result === '') {
            $result = '（无输出）';
        }

        if ($exitCode !== 0) {
            $result = "[退出码: {$exitCode}]\n" . $result;
        }

        return $result;
    }
}
