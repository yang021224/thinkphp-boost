<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Tools;

use think\App;

/**
 * 使用 PHP-CS-Fixer 格式化 ThinkPHP 项目代码
 */
class FormatCodeTool implements ToolInterface
{
    public function __construct(
        private readonly App $app
    ) {}

    public function getName(): string
    {
        return 'format_code';
    }

    public function getDescription(): string
    {
        return '使用 PHP-CS-Fixer 格式化 PHP 代码。支持格式化单个文件、目录或整个项目。可以 dry-run 模式预览变更而不实际修改文件。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'path' => [
                    'type'        => 'string',
                    'description' => '要格式化的文件或目录（相对于项目根目录），默认格式化整个 app/ 目录',
                ],
                'dry_run' => [
                    'type'        => 'boolean',
                    'description' => '是否只预览变更而不实际修改文件，默认 false',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): string
    {
        $rootPath = $this->app->getRootPath();
        $binary   = $rootPath . 'vendor/bin/php-cs-fixer';

        if (!file_exists($binary)) {
            return implode("\n", [
                '未找到 PHP-CS-Fixer。',
                '',
                '请运行以下命令安装：',
                '  composer require --dev friendsofphp/php-cs-fixer',
                '',
                '或者 thinkphp-boost 是作为开发依赖安装的话，运行：',
                '  composer install（不加 --no-dev）',
            ]);
        }

        $targetPath = $this->resolvePath($rootPath, $params['path'] ?? 'app');
        if ($targetPath === null) {
            return '错误：路径不存在或超出项目范围，请使用项目根目录内的相对路径。';
        }

        $configFile = $this->resolveConfigFile($rootPath);

        $dryRun = !empty($params['dry_run']);

        return $this->runFixer($binary, $targetPath, $configFile, $dryRun);
    }

    private function resolvePath(string $rootPath, string $relativePath): ?string
    {
        $fullPath = realpath($rootPath . ltrim($relativePath, '/'));

        if ($fullPath === false) {
            return null;
        }

        // 安全检查：不允许格式化项目根目录以外的路径
        if (!str_starts_with($fullPath, rtrim($rootPath, '/'))) {
            return null;
        }

        return $fullPath;
    }

    private function resolveConfigFile(string $rootPath): ?string
    {
        $configFile = $rootPath . '.php-cs-fixer.php';

        if (file_exists($configFile)) {
            return $configFile;
        }

        // 生成默认配置文件
        $this->generateDefaultConfig($configFile, $rootPath);

        return file_exists($configFile) ? $configFile : null;
    }

    private function generateDefaultConfig(string $configFile, string $rootPath): void
    {
        $content = <<<'PHP'
        <?php

        $finder = PhpCsFixer\Finder::create()
            ->in([
                __DIR__ . '/app',
                __DIR__ . '/route',
                __DIR__ . '/config',
            ])
            ->exclude(['vendor', 'runtime', 'public'])
            ->name('*.php');

        return (new PhpCsFixer\Config())
            ->setRules([
                '@PSR12'                     => true,
                'array_syntax'               => ['syntax' => 'short'],
                'ordered_imports'            => ['sort_algorithm' => 'alpha'],
                'no_unused_imports'          => true,
                'not_operator_with_successor_space' => true,
                'trailing_comma_in_multiline'=> true,
                'phpdoc_scalar'              => true,
                'unary_operator_spaces'      => true,
                'binary_operator_spaces'     => true,
                'blank_line_before_statement'=> ['statements' => ['break', 'continue', 'declare', 'return', 'throw', 'try']],
                'method_argument_space'      => ['on_multiline' => 'ensure_fully_multiline'],
                'single_trait_insert_per_statement' => true,
            ])
            ->setFinder($finder);
        PHP;

        @file_put_contents($configFile, $content);
    }

    private function runFixer(string $binary, string $targetPath, ?string $configFile, bool $dryRun): string
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($binary) . ' fix';
        $cmd .= ' ' . escapeshellarg($targetPath);

        if ($configFile !== null) {
            $cmd .= ' --config=' . escapeshellarg($configFile);
        }

        if ($dryRun) {
            $cmd .= ' --dry-run --diff';
        }

        $cmd .= ' --ansi 2>&1';

        $output   = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $result = implode("\n", $output);

        // 去掉 ANSI 颜色码，让输出更易读
        $result = preg_replace('/\x1b\[[0-9;]*m/', '', $result);

        $header = $dryRun
            ? "[预览模式] 以下文件需要格式化（未实际修改）：\n"
            : "[格式化完成]\n";

        if ($exitCode === 0) {
            return $header . ($result ?: '所有文件已符合规范，无需修改。');
        }

        if ($exitCode === 8) {
            // exit code 8 = files were fixed (not an error)
            return "[格式化完成] 以下文件已被修改：\n" . $result;
        }

        return "[退出码: {$exitCode}]\n" . $result;
    }
}
