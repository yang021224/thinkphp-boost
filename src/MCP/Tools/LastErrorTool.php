<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Tools;

use think\App;
use Yangmingzhi\ThinkphpBoost\MCP\Concerns\ReadsLogs;

/**
 * 最近错误工具 - 从 ThinkPHP 运行时日志中提取最近一条异常/错误
 */
class LastErrorTool implements ToolInterface
{
    use ReadsLogs;

    /** 最多向上追溯的字节数（4MB），防止扫描超大日志 */
    private const MAX_SCAN_BYTES = 4 * 1024 * 1024;

    public function __construct(
        private readonly App $app
    ) {}

    public function getName(): string
    {
        return 'get_last_error';
    }

    public function getDescription(): string
    {
        return '从 ThinkPHP 运行时日志中提取最近一条异常/错误，用于快速定位问题。';
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
        $logPath = $this->getLogPath();

        if (!is_dir($logPath)) {
            return "日志目录不存在：{$logPath}\n\nThinkPHP 应用需要先运行才会生成日志目录。";
        }

        $logFile = $this->findLatestLogFile($logPath);

        if ($logFile === null) {
            return "日志目录 {$logPath} 中没有找到任何日志文件。";
        }

        return $this->extractLastError($logFile);
    }

    private function extractLastError(string $filePath): string
    {
        if (!is_readable($filePath)) {
            return "无法读取日志文件：{$filePath}（权限不足）";
        }

        $fileSize = @filesize($filePath);
        if ($fileSize === false || $fileSize === 0) {
            return "日志文件为空：{$filePath}";
        }

        $fp = fopen($filePath, 'r');
        if ($fp === false) {
            return "无法打开日志文件：{$filePath}";
        }

        try {
            $content = $this->readTailContent($fp, $fileSize, self::MAX_SCAN_BYTES);
        } finally {
            fclose($fp);
        }

        if ($content === '') {
            return "日志文件内容为空：{$filePath}";
        }

        $lines = array_values(array_filter(
            explode("\n", $content),
            fn(string $l): bool => trim($l) !== ''
        ));

        if (empty($lines)) {
            return "日志文件中没有有效内容：{$filePath}";
        }

        $errorBlock = $this->findLastErrorBlock($lines);

        if ($errorBlock === null) {
            return "在日志文件 {$filePath} 中未找到错误记录。\n\n日志文件末尾内容（最后10行）：\n"
                . implode("\n", array_slice($lines, -10));
        }

        $output  = "日志文件：{$filePath}\n";
        $output .= str_repeat('=', 80) . "\n\n";
        $output .= $errorBlock;

        return $output;
    }

    /**
     * 在日志行数组中找最后一条错误块
     *
     * @param string[] $lines
     */
    private function findLastErrorBlock(array $lines): ?string
    {
        $totalLines   = count($lines);
        $lastErrorIdx = null;

        for ($i = $totalLines - 1; $i >= 0; $i--) {
            if ($this->isErrorLine($lines[$i])) {
                $lastErrorIdx = $i;
                break;
            }
        }

        if ($lastErrorIdx === null) {
            return null;
        }

        $blockStart = $lastErrorIdx;
        for ($i = $lastErrorIdx - 1; $i >= 0; $i--) {
            if ($this->isNewLogEntry($lines[$i])) {
                if ($this->isErrorLine($lines[$i])) {
                    $blockStart = $i;
                } else {
                    break;
                }
            } else {
                $blockStart = $i;
            }
        }

        $blockEnd = $lastErrorIdx;
        for ($i = $lastErrorIdx + 1; $i < $totalLines; $i++) {
            if ($this->isNewLogEntry($lines[$i]) && !$this->isErrorLine($lines[$i])) {
                break;
            }
            $blockEnd = $i;
        }

        $blockLines = array_slice($lines, $blockStart, $blockEnd - $blockStart + 1);
        $firstLine  = $blockLines[0] ?? '';
        $meta       = $this->parseLogMeta($firstLine);

        $result  = '';
        if ($meta['time'] !== '') {
            $result .= "发生时间：{$meta['time']}\n";
        }
        if ($meta['level'] !== '') {
            $result .= "错误级别：{$meta['level']}\n";
        }
        $result .= "\n错误详情：\n";
        $result .= implode("\n", $blockLines);
        $result .= "\n";

        return $result;
    }

    private function isErrorLine(string $line): bool
    {
        return str_contains($line, 'ERRO')
            || stripos($line, '[error]') !== false
            || str_contains($line, 'Exception')
            || str_contains($line, '#0 ');
    }

    private function isNewLogEntry(string $line): bool
    {
        return str_starts_with(ltrim($line), '[');
    }

    private function parseLogMeta(string $line): array
    {
        $time  = '';
        $level = '';

        if (preg_match('/^\[([^\]]+)\]\[([^\]]+)\]/', $line, $m)) {
            $time  = $m[1];
            $level = $m[2];
        } elseif (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
            $time = $m[1];
        }

        return ['time' => $time, 'level' => $level];
    }
}
