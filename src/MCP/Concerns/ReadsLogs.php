<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Concerns;

use think\App;

/**
 * 日志文件读取公共逻辑，供 LogsTool 和 LastErrorTool 复用
 */
trait ReadsLogs
{
    private function getLogPath(): string
    {
        try {
            /** @var App $app */
            $configured = $this->app->config->get('boost.logs.path');
            if ($configured && is_string($configured)) {
                return rtrim($configured, DIRECTORY_SEPARATOR);
            }
        } catch (\Throwable) {
        }

        return rtrim($this->app->getRuntimePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'log';
    }

    /**
     * 找最新日志文件（按文件修改时间）
     */
    private function findLatestLogFile(string $logPath): ?string
    {
        $latestFile = null;
        $latestTime = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($logPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'log') {
                    $mtime = $file->getMTime();
                    if ($mtime > $latestTime) {
                        $latestTime = $mtime;
                        $latestFile = $file->getPathname();
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $latestFile;
    }

    /**
     * 从文件末尾读取最多 $maxBytes 字节内容，自动跳过首个不完整行
     *
     * @param resource $fp
     */
    private function readTailContent($fp, int $fileSize, int $maxBytes): string
    {
        $scanLimit = min($fileSize, $maxBytes);
        $startPos  = $fileSize - $scanLimit;

        fseek($fp, $startPos);
        $buffer = fread($fp, $scanLimit);

        if ($buffer === false) {
            return '';
        }

        if ($startPos > 0) {
            $firstNewline = strpos($buffer, "\n");
            if ($firstNewline !== false) {
                $buffer = substr($buffer, $firstNewline + 1);
            }
        }

        return $buffer;
    }

    /**
     * 从文件末尾反向读取指定行数（逐块读取，避免加载整个文件）
     */
    private function readTailLines(string $filePath, int $lines): string
    {
        $fp = fopen($filePath, 'r');
        if ($fp === false) {
            return '';
        }

        $buffer     = '';
        $chunkSize  = 8192;
        $linesFound = 0;

        fseek($fp, 0, SEEK_END);
        $position = ftell($fp);

        while ($position > 0 && $linesFound < $lines) {
            $chunkSize = min($chunkSize, $position);
            $position -= $chunkSize;
            fseek($fp, $position);

            $chunk      = fread($fp, $chunkSize);
            $buffer     = $chunk . $buffer;
            $linesFound = substr_count($buffer, "\n");
        }

        fclose($fp);

        if ($position > 0) {
            $firstNewline = strpos($buffer, "\n");
            if ($firstNewline !== false) {
                $buffer = substr($buffer, $firstNewline + 1);
            }
        }

        return $buffer;
    }

    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
