<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Tools;

use think\App;
use Yangmingzhi\ThinkphpBoost\MCP\Concerns\ReadsLogs;

/**
 * 日志工具 - 读取 ThinkPHP runtime/log 目录下的日志文件
 */
class LogsTool implements ToolInterface
{
    use ReadsLogs;

    private const DEFAULT_LINES = 100;
    private const MAX_LINES     = 1000;

    public function __construct(
        private readonly App $app
    ) {}

    public function getName(): string
    {
        return 'get_logs';
    }

    public function getDescription(): string
    {
        return '读取 ThinkPHP 应用的运行日志（runtime/log 目录）。支持按日期筛选和指定行数。';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'lines' => [
                    'type'        => 'integer',
                    'description' => '读取的日志行数（默认 100，最多 1000）',
                    'minimum'     => 1,
                    'maximum'     => self::MAX_LINES,
                ],
                'date' => [
                    'type'        => 'string',
                    'description' => '日志日期，格式为 YYYYMMDD，例如 "20240516"（可选，不填则读取最新日志）',
                    'pattern'     => '^[0-9]{8}$',
                ],
                'level' => [
                    'type'        => 'string',
                    'description' => '过滤日志级别：error、warning、info、debug（可选，不填则返回所有级别）',
                    'enum'        => ['error', 'warning', 'info', 'debug', 'notice', 'sql'],
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): string
    {
        $lines    = min((int)($params['lines'] ?? self::DEFAULT_LINES), self::MAX_LINES);
        $lines    = max($lines, 1);
        $date     = isset($params['date']) ? trim((string)$params['date']) : null;
        $level    = isset($params['level']) ? strtolower(trim((string)$params['level'])) : null;

        // 验证日期格式
        if ($date !== null && !preg_match('/^\d{8}$/', $date)) {
            return "错误：date 参数格式不正确，应为 YYYYMMDD，例如 \"20240516\"。";
        }

        $logPath = $this->getLogPath();

        if (!is_dir($logPath)) {
            return "日志目录不存在：{$logPath}\n\nThinkPHP 应用需要先运行才会生成日志目录。";
        }

        if ($date !== null) {
            return $this->readLogByDate($logPath, $date, $lines, $level);
        }

        return $this->readLatestLog($logPath, $lines, $level);
    }

    /**
     * 读取最新的日志文件
     */
    private function readLatestLog(string $logPath, int $lines, ?string $level): string
    {
        $logFile = $this->findLatestLogFile($logPath);

        if ($logFile === null) {
            return "日志目录 {$logPath} 中没有找到任何日志文件。";
        }

        return $this->readLogFile($logFile, $lines, $level);
    }

    /**
     * 按日期读取日志文件
     */
    private function readLogByDate(string $logPath, string $date, int $lines, ?string $level): string
    {
        // ThinkPHP 日志目录结构：runtime/log/YYYYMM/DD.log
        $year  = substr($date, 0, 4);
        $month = substr($date, 4, 2);
        $day   = substr($date, 6, 2);

        // 可能的路径格式
        $possiblePaths = [
            $logPath . DIRECTORY_SEPARATOR . "{$year}{$month}" . DIRECTORY_SEPARATOR . "{$day}.log",
            $logPath . DIRECTORY_SEPARATOR . "{$year}-{$month}" . DIRECTORY_SEPARATOR . "{$day}.log",
            $logPath . DIRECTORY_SEPARATOR . "{$date}.log",
            $logPath . DIRECTORY_SEPARATOR . "{$year}" . DIRECTORY_SEPARATOR . "{$month}" . DIRECTORY_SEPARATOR . "{$day}.log",
        ];

        foreach ($possiblePaths as $path) {
            if (is_file($path)) {
                return $this->readLogFile($path, $lines, $level);
            }
        }

        return "未找到日期 {$date} 的日志文件。\n\n已搜索以下路径：\n" . implode("\n", $possiblePaths);
    }

    /**
     * 读取日志文件内容（取最后 N 行）
     */
    private function readLogFile(string $filePath, int $lines, ?string $level): string
    {
        if (!is_readable($filePath)) {
            return "无法读取日志文件：{$filePath}（权限不足）";
        }

        $fileSize = filesize($filePath);
        if ($fileSize === 0) {
            return "日志文件为空：{$filePath}";
        }

        // 从文件末尾读取，避免加载超大文件
        $content = $this->readTailLines($filePath, $lines * 2); // 多读一些以便过滤

        $allLines = explode("\n", $content);
        $allLines = array_filter(array_map('rtrim', $allLines));

        // 按级别过滤
        if ($level !== null) {
            $allLines = array_filter($allLines, function (string $line) use ($level): bool {
                return stripos($line, "[{$level}]") !== false
                    || stripos($line, " {$level} ") !== false
                    || stripos($line, strtoupper($level)) !== false;
            });
        }

        $allLines = array_values($allLines);

        // 取最后 N 行
        if (count($allLines) > $lines) {
            $allLines = array_slice($allLines, -$lines);
        }

        if (empty($allLines)) {
            $levelMsg = $level ? "（级别: {$level}）" : '';
            return "日志文件 {$filePath} 中没有找到匹配的日志记录{$levelMsg}。";
        }

        $output = sprintf(
            "日志文件：%s\n文件大小：%s\n显示最后 %d 行%s\n",
            $filePath,
            $this->formatFileSize($fileSize),
            count($allLines),
            $level ? "（级别过滤：{$level}）" : ''
        );
        $output .= str_repeat('=', 80) . "\n\n";
        $output .= implode("\n", $allLines);

        return $output;
    }

}
