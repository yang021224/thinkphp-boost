<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Tools;

use think\App;

/**
 * 应用信息工具 - 获取 ThinkPHP 应用的基本运行信息
 */
class AppInfoTool implements ToolInterface
{
    public function __construct(
        private readonly App $app
    ) {}

    public function getName(): string
    {
        return 'get_app_info';
    }

    public function getDescription(): string
    {
        return '获取 ThinkPHP 应用的基本信息，包括 PHP 版本、框架版本、已安装包列表、Model 文件列表、运行环境。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => (object)[],
            'required'   => [],
        ];
    }

    public function execute(array $params): string
    {
        $output = '';

        // PHP 版本
        $output .= "=== PHP 环境 ===\n";
        $output .= "PHP 版本：" . PHP_VERSION . "\n";
        $output .= "运行模式：" . php_sapi_name() . "\n";

        // Debug 模式
        try {
            $isDebug = $this->app->isDebug();
            $output .= "Debug 模式：" . ($isDebug ? '开启' : '关闭') . "\n";
        } catch (\Throwable $e) {
            $output .= "Debug 模式：（无法获取）\n";
        }

        // ThinkPHP 版本
        $output .= "\n=== ThinkPHP 框架 ===\n";
        $output .= "框架版本：" . $this->getThinkPhpVersion() . "\n";

        // 数据库驱动
        $output .= "\n=== 数据库配置 ===\n";
        $output .= $this->getDatabaseInfo();

        // 已安装包列表
        $output .= "\n=== 已安装 Composer 包（非 topthink 内部包，前30个）===\n";
        $output .= $this->getInstalledPackages();

        // Model 文件列表
        $output .= "\n=== Model 文件列表 ===\n";
        $output .= $this->getModelList();

        return $output;
    }

    /**
     * 获取 ThinkPHP 版本号
     */
    private function getThinkPhpVersion(): string
    {
        // 优先读取 composer.json
        $composerFile = $this->app->getRootPath() . 'vendor/topthink/framework/composer.json';
        if (is_file($composerFile)) {
            try {
                $json = file_get_contents($composerFile);
                if ($json !== false) {
                    $data = json_decode($json, true);
                    if (isset($data['version']) && is_string($data['version'])) {
                        return $data['version'];
                    }
                }
            } catch (\Throwable $e) {
                // 继续尝试常量
            }
        }

        // 兜底：使用常量
        if (defined('\think\App::VERSION')) {
            return \think\App::VERSION;
        }

        return '未知';
    }

    /**
     * 获取数据库信息
     */
    private function getDatabaseInfo(): string
    {
        try {
            $config         = $this->app->config->get('database');
            $defaultConn    = $config['default'] ?? 'mysql';
            $connections    = $config['connections'] ?? [];
            $connConfig     = $connections[$defaultConn] ?? [];
            $type           = $connConfig['type'] ?? $connConfig['driver'] ?? '未知';

            return "默认连接：{$defaultConn}\n驱动类型：{$type}\n";
        } catch (\Throwable $e) {
            return "（无法读取数据库配置：{$e->getMessage()}）\n";
        }
    }

    /**
     * 获取已安装包列表
     */
    private function getInstalledPackages(): string
    {
        $installedFile = $this->app->getRootPath() . 'vendor/composer/installed.json';

        if (!is_file($installedFile)) {
            return "（找不到 vendor/composer/installed.json）\n";
        }

        try {
            $json = file_get_contents($installedFile);
            if ($json === false) {
                return "（无法读取 installed.json）\n";
            }

            $data     = json_decode($json, true);
            $packages = $data['packages'] ?? $data;

            if (!is_array($packages)) {
                return "（installed.json 格式异常）\n";
            }

            // 过滤 topthink/ 开头的内部包
            $filtered = array_filter($packages, function (array $pkg): bool {
                $name = $pkg['name'] ?? '';
                return !str_starts_with($name, 'topthink/');
            });

            // 只显示前30个
            $filtered = array_slice(array_values($filtered), 0, 30);

            if (empty($filtered)) {
                return "（没有非 topthink 包）\n";
            }

            $lines = [];
            foreach ($filtered as $pkg) {
                $name    = $pkg['name'] ?? '?';
                $version = $pkg['version'] ?? '?';
                $lines[] = sprintf("  %-45s %s", $name, $version);
            }

            return implode("\n", $lines) . "\n";
        } catch (\Throwable $e) {
            return "（解析 installed.json 失败：{$e->getMessage()}）\n";
        }
    }

    /**
     * 获取 Model 文件列表
     */
    private function getModelList(): string
    {
        $appPath    = $this->app->getAppPath();
        $rootPath   = $this->app->getRootPath();
        $modelFiles = [];

        // 单应用模式：app/model/
        $singleModelDir = $appPath . 'model' . DIRECTORY_SEPARATOR;
        if (is_dir($singleModelDir)) {
            $this->collectPhpFiles($singleModelDir, $modelFiles);
        }

        // 多应用模式：app/*/model/
        if (is_dir($appPath)) {
            $appDirs = glob($appPath . '*', GLOB_ONLYDIR);
            if ($appDirs) {
                foreach ($appDirs as $appDir) {
                    $modelDir = $appDir . DIRECTORY_SEPARATOR . 'model' . DIRECTORY_SEPARATOR;
                    if (is_dir($modelDir)) {
                        $this->collectPhpFiles($modelDir, $modelFiles);
                    }
                }
            }
        }

        if (empty($modelFiles)) {
            return "（未找到任何 Model 文件）\n";
        }

        $lines = [];
        foreach ($modelFiles as $file) {
            $className = pathinfo($file, PATHINFO_FILENAME);
            // 去掉 rootPath 前缀，显示相对路径
            $relativePath = str_replace($rootPath, '', $file);
            $lines[] = sprintf("  %-30s %s", $className, $relativePath);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * 收集目录下所有 PHP 文件
     */
    private function collectPhpFiles(string $dir, array &$files): void
    {
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        } catch (\Throwable $e) {
            // 忽略无法访问的目录
        }
    }
}
