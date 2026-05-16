<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Install\Agents;

class Cursor extends Agent
{
    public function name(): string
    {
        return 'cursor';
    }

    public function displayName(): string
    {
        return 'Cursor';
    }

    public function mcpConfigPath(): string
    {
        return '.cursor/mcp.json';
    }

    protected function systemCommand(): ?string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'cmd /c where cursor 2>nul' : 'command -v cursor';
    }

    protected function projectIndicators(): array
    {
        return ['.cursor', '.cursor/mcp.json'];
    }
}
