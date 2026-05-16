<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Install\Agents;

class ClaudeCode extends Agent
{
    public function name(): string
    {
        return 'claude_code';
    }

    public function displayName(): string
    {
        return 'Claude Code';
    }

    public function mcpConfigPath(): string
    {
        return '.mcp.json';
    }

    protected function systemCommand(): ?string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'cmd /c where claude 2>nul' : 'command -v claude';
    }

    protected function projectIndicators(): array
    {
        return ['.claude', 'CLAUDE.md', '.mcp.json'];
    }
}
