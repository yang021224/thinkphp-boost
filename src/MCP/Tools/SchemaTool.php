<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Tools;

use think\App;

/**
 * 数据库 Schema 工具 - 查询数据库表结构
 */
class SchemaTool implements ToolInterface
{
    private const CACHE_TTL = 20; // 秒

    public function __construct(
        private readonly App $app
    ) {}

    public function getName(): string
    {
        return 'get_database_schema';
    }

    public function getDescription(): string
    {
        return '查询 ThinkPHP 应用的数据库表结构，包括字段名、类型、是否可空、默认值和注释。可指定单张表或获取全部表结构。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'table' => [
                    'type'        => 'string',
                    'description' => '要查询的数据库表名（可选）。不填则返回所有表的结构。',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): string
    {
        $targetTable = isset($params['table']) ? trim((string)$params['table']) : null;

        try {
            $pdo = $this->getPdo();
        } catch (\Throwable $e) {
            return "无法连接到数据库：{$e->getMessage()}\n\n请检查 .env 或 config/database.php 中的数据库配置。";
        }

        try {
            if ($targetTable !== null && $targetTable !== '') {
                return $this->describeTable($pdo, $targetTable);
            }

            return $this->describeAllTables($pdo);
        } catch (\Throwable $e) {
            return "查询数据库结构时出错：{$e->getMessage()}";
        }
    }

    /**
     * 获取 PDO 连接
     */
    private function getPdo(): \PDO
    {
        // 尝试从 ThinkPHP 数据库配置获取连接参数
        $config = $this->getDatabaseConfig();

        $dsn      = $config['dsn'] ?? null;
        $hostname = $config['hostname'] ?? $config['host'] ?? '127.0.0.1';
        $database = $config['database'] ?? $config['dbname'] ?? '';
        $username = $config['username'] ?? $config['user'] ?? 'root';
        $password = $config['password'] ?? $config['pass'] ?? '';
        $hostport = $config['hostport'] ?? $config['port'] ?? 3306;
        $charset  = $config['charset'] ?? 'utf8mb4';

        if (empty($dsn)) {
            $dsn = "mysql:host={$hostname};port={$hostport};dbname={$database};charset={$charset}";
        }

        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT            => 5,
        ];

        return new \PDO($dsn, $username, $password, $options);
    }

    /**
     * 获取数据库配置
     */
    private function getDatabaseConfig(): array
    {
        try {
            $config = $this->app->config->get('database');

            if (!empty($config)) {
                // ThinkPHP 8.x 默认连接配置
                $defaultConn = $config['default'] ?? 'mysql';
                $connections = $config['connections'] ?? [];

                if (!empty($connections[$defaultConn])) {
                    return $connections[$defaultConn];
                }

                // 兼容旧格式
                if (isset($config['hostname']) || isset($config['host'])) {
                    return $config;
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "SchemaTool: 读取数据库配置失败: " . $e->getMessage() . "\n");
        }

        // 尝试读取 .env 文件
        return $this->getConfigFromEnv();
    }

    /**
     * 从 .env 文件读取数据库配置
     */
    private function getConfigFromEnv(): array
    {
        $envFile = $this->app->getRootPath() . '.env';
        $config  = [];

        if (is_file($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$key, $value]  = explode('=', $line, 2);
                $key            = trim($key);
                $value          = trim($value, " \t\n\r\0\x0B\"'");

                $map = [
                    'DB_HOST'     => 'hostname',
                    'DB_PORT'     => 'hostport',
                    'DB_DATABASE' => 'database',
                    'DB_USERNAME' => 'username',
                    'DB_PASSWORD' => 'password',
                    'DB_CHARSET'  => 'charset',
                ];

                if (isset($map[$key])) {
                    $config[$map[$key]] = $value;
                }
            }
        }

        return $config;
    }

    /**
     * 描述所有表的结构（带 20 秒文件缓存）
     */
    private function describeAllTables(\PDO $pdo): string
    {
        $cacheFile = $this->getCacheFile();

        if ($cacheFile !== null && is_file($cacheFile)) {
            $mtime = filemtime($cacheFile);
            if ($mtime !== false && (time() - $mtime) < self::CACHE_TTL) {
                $cached = file_get_contents($cacheFile);
                if ($cached !== false && $cached !== '') {
                    return $cached;
                }
            }
        }

        $stmt   = $pdo->query('SHOW TABLES');
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($tables)) {
            return "数据库中没有任何数据表。";
        }

        $output = sprintf("数据库共有 %d 张表\n", count($tables));
        $output .= str_repeat('=', 80) . "\n\n";

        foreach ($tables as $table) {
            $output .= $this->describeTable($pdo, $table);
            $output .= "\n";
        }

        if ($cacheFile !== null) {
            @file_put_contents($cacheFile, $output);
        }

        return $output;
    }

    private function getCacheFile(): ?string
    {
        try {
            $cacheDir = rtrim($this->app->getRuntimePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cache';
            if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
                return null;
            }
            return $cacheDir . DIRECTORY_SEPARATOR . 'boost_schema.cache';
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 描述单个表的结构
     */
    private function describeTable(\PDO $pdo, string $table): string
    {
        // 验证表名（防止 SQL 注入）
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            return "表名格式不合法：{$table}";
        }

        // 检查表是否存在
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        if ($stmt->rowCount() === 0) {
            return "表 `{$table}` 不存在。";
        }

        $output = "表: {$table}\n";
        $output .= str_repeat('-', 60) . "\n";

        // 获取字段信息
        $stmt    = $pdo->query("DESCRIBE `{$table}`");
        $columns = $stmt->fetchAll();

        // 获取字段注释（通过 INFORMATION_SCHEMA）
        $comments = $this->getColumnComments($pdo, $table);

        // 表头
        $output .= sprintf(
            "%-25s %-20s %-8s %-10s %-15s %s\n",
            '字段名',
            '类型',
            '可空',
            '键',
            '默认值',
            '注释'
        );
        $output .= str_repeat('-', 100) . "\n";

        foreach ($columns as $column) {
            $fieldName = $column['Field'];
            $type      = $column['Type'];
            $nullable  = $column['Null'] === 'YES' ? '是' : '否';
            $key       = $column['Key'] ?? '';
            $default   = $column['Default'] ?? 'NULL';
            $extra     = $column['Extra'] ?? '';
            $comment   = $comments[$fieldName] ?? '';

            if ($extra) {
                $type .= " ({$extra})";
            }

            $keyLabel = match ($key) {
                'PRI' => 'PRIMARY',
                'UNI' => 'UNIQUE',
                'MUL' => 'INDEX',
                default => $key,
            };

            $output .= sprintf(
                "%-25s %-20s %-8s %-10s %-15s %s\n",
                $fieldName,
                $type,
                $nullable,
                $keyLabel,
                $default === null ? 'NULL' : (string)$default,
                $comment
            );
        }

        // 获取索引信息
        $output .= "\n索引:\n";
        try {
            $stmt    = $pdo->query("SHOW INDEX FROM `{$table}`");
            $indexes = $stmt->fetchAll();

            $indexGroups = [];
            foreach ($indexes as $index) {
                $name = $index['Key_name'];
                $indexGroups[$name][] = $index['Column_name'];
            }

            foreach ($indexGroups as $name => $columns) {
                $type = $name === 'PRIMARY' ? 'PRIMARY KEY' : (
                    isset($indexes[array_key_first(array_filter(
                        $indexes,
                        fn($i) => $i['Key_name'] === $name
                    ))]['Non_unique']) && $indexes[array_key_first(array_filter(
                        $indexes,
                        fn($i) => $i['Key_name'] === $name
                    ))]['Non_unique'] == 0 ? 'UNIQUE' : 'INDEX'
                );
                $output .= sprintf("  %-15s %-10s %s\n", $name, $type, implode(', ', $columns));
            }
        } catch (\Throwable $e) {
            $output .= "  (无法获取索引信息)\n";
        }

        return $output;
    }

    /**
     * 获取字段注释
     */
    private function getColumnComments(\PDO $pdo, string $table): array
    {
        $comments = [];

        try {
            // 从 INFORMATION_SCHEMA 获取数据库名
            $stmt   = $pdo->query('SELECT DATABASE()');
            $dbName = $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT COLUMN_NAME, COLUMN_COMMENT
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
            );
            $stmt->execute([$dbName, $table]);

            while ($row = $stmt->fetch()) {
                if (!empty($row['COLUMN_COMMENT'])) {
                    $comments[$row['COLUMN_NAME']] = $row['COLUMN_COMMENT'];
                }
            }
        } catch (\Throwable $e) {
            // 忽略注释获取失败
        }

        return $comments;
    }
}
