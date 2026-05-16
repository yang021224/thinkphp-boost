<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Install\Agents;

class Codex extends Agent
{
    public function name(): string
    {
        return 'codex';
    }

    public function displayName(): string
    {
        return 'Codex (OpenAI)';
    }

    public function mcpConfigPath(): string
    {
        return '.codex/mcp.json';
    }

    protected function systemCommand(): ?string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'cmd /c where codex 2>nul' : 'command -v codex';
    }

    protected function projectIndicators(): array
    {
        return ['.codex', '.codex/mcp.json'];
    }

    protected function mcpServerConfig(string $rootPath): array
    {
        // Codex 需要完整的绝对路径
        return [
            'command' => PHP_BINARY,
            'args'    => ['think', 'boost:serve'],
            'cwd'     => rtrim($rootPath, DIRECTORY_SEPARATOR),
        ];
    }
}
