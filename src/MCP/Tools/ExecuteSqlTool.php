<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Tools;

use think\App;

/**
 * SQL 执行工具 - 在 ThinkPHP 数据库连接上执行只读 SQL 查询
 */
class ExecuteSqlTool implements ToolInterface
{
    /** 允许执行的只读 SQL 指令（含 CTE 和标准 SQL：WITH/VALUES/TABLE） */
    private const ALLOWED_COMMANDS = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH', 'VALUES', 'TABLE'];

    /** 最多返回的行数 */
    private const MAX_ROWS = 100;

    /** 格式化表格时每列最大宽度 */
    private const MAX_COL_WIDTH = 40;

    public function __construct(
        private readonly App $app
    ) {}

    public function getName(): string
    {
        return 'execute_sql';
    }

    public function getDescription(): string
    {
        return '在 ThinkPHP 数据库连接上执行 SQL 查询，只允许只读查询（SELECT/SHOW/DESCRIBE/EXPLAIN/WITH CTE/VALUES/TABLE）。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'sql' => [
                    'type'        => 'string',
                    'description' => '要执行的 SQL 语句，只支持 SELECT、SHOW、DESCRIBE、DESC、EXPLAIN、WITH（CTE）、VALUES、TABLE。',
                ],
                'connection' => [
                    'type'        => 'string',
                    'description' => '数据库连接名（可选），默认使用项目配置的默认连接。',
                ],
            ],
            'required' => ['sql'],
        ];
    }

    public function execute(array $params): string
    {
        $sql        = trim((string)($params['sql'] ?? ''));
        $connection = isset($params['connection']) ? trim((string)$params['connection']) : null;

        if ($sql === '') {
            return '错误：sql 参数不能为空。';
        }

        // 安全检查：只允许只读指令
        $safetyError = $this->checkSqlSafety($sql);
        if ($safetyError !== null) {
            return $safetyError;
        }

        // 执行查询
        try {
            $rows = $this->runQuery($sql, $connection);
        } catch (\Throwable $e) {
            return $this->formatDbError($e);
        }

        if (empty($rows)) {
            return "查询执行成功，结果为空（0 行）。\n\nSQL：{$sql}";
        }

        return $this->formatResultTable($sql, $rows);
    }

    /**
     * 检查 SQL 安全性，只允许只读指令
     *
     * @return string|null 返回 null 表示通过，返回字符串表示错误信息
     */
    private function checkSqlSafety(string $sql): ?string
    {
        $stripped = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);
        $command  = strtoupper(explode(' ', $stripped, 2)[0]);

        if (!in_array($command, self::ALLOWED_COMMANDS, true)) {
            $allowed = implode('、', self::ALLOWED_COMMANDS);
            return "安全限制：只允许只读查询。\n\n当前指令：{$command}\n允许的指令：{$allowed}";
        }

        // WITH 语句必须以 SELECT/VALUES/TABLE 结尾（防止 WITH...INSERT 等写操作）
        if ($command === 'WITH' && !$this->isReadOnlyCte($stripped)) {
            return "安全限制：WITH（CTE）语句末尾必须是 SELECT、VALUES 或 TABLE，不允许写操作。";
        }

        return null;
    }

    /**
     * 验证 WITH...CTE 语句末尾是只读操作
     */
    private function isReadOnlyCte(string $sql): bool
    {
        // 提取最后一个括号块之后的关键字
        // 简单策略：去掉所有括号内容后，检查末尾主语句的第一个词
        $depth   = 0;
        $outside = '';
        $len     = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($depth === 0) {
                $outside .= $ch;
            }
        }

        // 找括号外最后一个 SQL 关键字块
        $parts = array_filter(array_map('trim', preg_split('/\s+/', $outside) ?: []));
        $parts = array_values($parts);

        // 去掉 WITH、CTE 名称和 AS 等，找第一个实质关键字（在最后一个 AS 之后）
        $lastAsPos = -1;
        foreach ($parts as $idx => $part) {
            if (strtoupper($part) === 'AS') {
                $lastAsPos = $idx;
            }
        }

        $finalKeyword = strtoupper($parts[$lastAsPos + 1] ?? '');

        return in_array($finalKeyword, ['SELECT', 'VALUES', 'TABLE'], true);
    }

    /**
     * 使用 ThinkPHP Db 门面执行查询
     *
     * @return array<int, array<string, mixed>>
     */
    private function runQuery(string $sql, ?string $connection): array
    {
        /** @var \think\DbManager $db */
        $db = $this->app->db;

        if ($connection !== null && $connection !== '') {
            $result = $db->connect($connection)->query($sql);
        } else {
            $result = $db->query($sql);
        }

        // ThinkPHP query() 返回数组
        if (!is_array($result)) {
            return [];
        }

        return $result;
    }

    /**
     * 将查询结果格式化为表格文本
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function formatResultTable(string $sql, array $rows): string
    {
        $totalRows  = count($rows);
        $truncated  = $totalRows > self::MAX_ROWS;
        $displayRows = $truncated ? array_slice($rows, 0, self::MAX_ROWS) : $rows;

        // 获取列名
        $columns = array_keys($displayRows[0]);

        // 计算每列宽度
        $colWidths = [];
        foreach ($columns as $col) {
            $colWidths[$col] = min(mb_strlen((string)$col), self::MAX_COL_WIDTH);
        }

        foreach ($displayRows as $row) {
            foreach ($columns as $col) {
                $val              = $this->formatCellValue($row[$col] ?? null);
                $len              = min(mb_strlen($val), self::MAX_COL_WIDTH);
                $colWidths[$col]  = max($colWidths[$col], $len);
            }
        }

        // 构建表头
        $separator = '+';
        $header    = '|';
        foreach ($columns as $col) {
            $width      = $colWidths[$col];
            $separator .= str_repeat('-', $width + 2) . '+';
            $header    .= ' ' . mb_str_pad($col, $width) . ' |';
        }

        $output  = "SQL：{$sql}\n\n";
        $output .= $separator . "\n";
        $output .= $header . "\n";
        $output .= $separator . "\n";

        foreach ($displayRows as $row) {
            $line = '|';
            foreach ($columns as $col) {
                $val    = $this->formatCellValue($row[$col] ?? null);
                $val    = $this->truncateCell($val, self::MAX_COL_WIDTH);
                $width  = $colWidths[$col];
                $line  .= ' ' . mb_str_pad($val, $width) . ' |';
            }
            $output .= $line . "\n";
        }

        $output .= $separator . "\n";

        if ($truncated) {
            $output .= "\n... 共 {$totalRows} 行，已截断显示前 " . self::MAX_ROWS . " 行\n";
        } else {
            $output .= "\n共 {$totalRows} 行\n";
        }

        return $output;
    }

    /**
     * 格式化单元格值
     */
    private function formatCellValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string)$value;
    }

    /**
     * 截断超长单元格值
     */
    private function truncateCell(string $value, int $maxLen): string
    {
        if (mb_strlen($value) <= $maxLen) {
            return $value;
        }

        return mb_substr($value, 0, $maxLen - 3) . '...';
    }

    /**
     * 格式化数据库错误（不暴露连接密码等敏感信息）
     */
    private function formatDbError(\Throwable $e): string
    {
        $message = $e->getMessage();

        // 去除可能包含密码的连接字符串（如 PDO DSN）
        $message = preg_replace('/password=[^\s;]*/i', 'password=***', $message) ?? $message;
        $message = preg_replace('/:[^:@]*@/', ':***@', $message) ?? $message;

        return "数据库查询失败：{$message}\n\n类型：" . get_class($e);
    }
}
