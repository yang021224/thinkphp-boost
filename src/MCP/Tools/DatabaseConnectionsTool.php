<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Tools;

use think\App;

/**
 * 数据库连接检查工具 - 列出所有配置的数据库连接并检查连通性
 */
class DatabaseConnectionsTool implements ToolInterface
{
    /** 连接测试超时秒数 */
    private const CONNECT_TIMEOUT = 3;

    public function __construct(
        private readonly App $app
    ) {}

    public function getName(): string
    {
        return 'database_connections';
    }

    public function getDescription(): string
    {
        return '列出 ThinkPHP 应用 config/database.php 中所有配置的数据库连接，并逐一测试连通性，返回每个连接的驱动、主机、数据库名及连接状态。';
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
        try {
            $dbConfig = $this->app->config->get('database');
        } catch (\Throwable $e) {
            return "无法读取数据库配置：{$e->getMessage()}";
        }

        if (empty($dbConfig)) {
            return "未找到 database 配置，请检查 config/database.php 是否存在。";
        }

        $default     = $dbConfig['default'] ?? 'mysql';
        $connections = $dbConfig['connections'] ?? [];

        if (empty($connections)) {
            return "database 配置中没有找到任何连接（connections 为空）。";
        }

        $output  = sprintf("共找到 %d 个数据库连接（默认：%s）\n", count($connections), $default);
        $output .= str_repeat('=', 80) . "\n\n";

        foreach ($connections as $name => $config) {
            $output .= $this->checkConnection($name, $config, $name === $default);
            $output .= "\n";
        }

        return $output;
    }

    private function checkConnection(string $name, array $config, bool $isDefault): string
    {
        $driver   = $config['type'] ?? $config['driver'] ?? 'mysql';
        $host     = $config['hostname'] ?? $config['host'] ?? '127.0.0.1';
        $port     = $config['hostport'] ?? $config['port'] ?? $this->defaultPort($driver);
        $database = $config['database'] ?? $config['dbname'] ?? '(未设置)';
        $username = $config['username'] ?? $config['user'] ?? '';
        $charset  = $config['charset'] ?? 'utf8mb4';
        $prefix   = $config['prefix'] ?? '';

        $label = $isDefault ? " [默认]" : '';
        $output  = "连接名：{$name}{$label}\n";
        $output .= sprintf("  驱动：%-10s 主机：%s:%s\n", $driver, $host, $port);
        $output .= sprintf("  数据库：%-20s 字符集：%s\n", $database, $charset);
        if ($prefix !== '') {
            $output .= "  表前缀：{$prefix}\n";
        }

        [$ok, $msg] = $this->testConnection($driver, $host, (int)$port, $database, $username, $config);

        $status  = $ok ? '✓ 连通' : '✗ 失败';
        $output .= "  状态：{$status}";
        if ($msg !== '') {
            $output .= " — {$msg}";
        }
        $output .= "\n";

        return $output;
    }

    /**
     * @return array{bool, string} [是否成功, 错误信息或空字符串]
     */
    private function testConnection(
        string $driver,
        string $host,
        int $port,
        string $database,
        string $username,
        array $config
    ): array {
        $password = $config['password'] ?? $config['pass'] ?? '';
        $charset  = $config['charset'] ?? 'utf8mb4';

        try {
            $dsn = match (strtolower($driver)) {
                'mysql', 'mariadb' => "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
                'pgsql', 'postgres', 'postgresql' => "pgsql:host={$host};port={$port};dbname={$database}",
                'sqlite' => "sqlite:{$database}",
                'sqlsrv', 'mssql' => "sqlsrv:Server={$host},{$port};Database={$database}",
                default => null,
            };

            if ($dsn === null) {
                return [false, "不支持的驱动类型：{$driver}"];
            }

            $options = [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT,
            ];

            $pdo = new \PDO($dsn, $username, $password, $options);

            // 简单验证：执行一条轻量 SQL
            $pdo->query(match (strtolower($driver)) {
                'pgsql', 'postgres', 'postgresql' => 'SELECT 1',
                'sqlite' => 'SELECT 1',
                default => 'SELECT 1',
            });

            return [true, ''];
        } catch (\PDOException $e) {
            // 脱敏：去除密码信息
            $msg = preg_replace('/Access denied for user \'[^\']*\'@/', "Access denied for user '***'@", $e->getMessage()) ?? $e->getMessage();
            return [false, $msg];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    private function defaultPort(string $driver): int
    {
        return match (strtolower($driver)) {
            'pgsql', 'postgres', 'postgresql' => 5432,
            'sqlsrv', 'mssql' => 1433,
            default => 3306,
        };
    }
}
