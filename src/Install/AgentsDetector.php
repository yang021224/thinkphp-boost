<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Install;

use Yangmingzhi\ThinkphpBoost\Install\Agents\Agent;
use Yangmingzhi\ThinkphpBoost\Install\Agents\ClaudeCode;
use Yangmingzhi\ThinkphpBoost\Install\Agents\Cursor;
use Yangmingzhi\ThinkphpBoost\Install\Agents\Codex;

class AgentsDetector
{
    /** @var Agent[] 所有支持的 Agent */
    private array $agents;

    public function __construct()
    {
        $this->agents = [
            new ClaudeCode(),
            new Cursor(),
            new Codex(),
        ];
    }

    /**
     * 返回所有支持的 Agent 实例
     *
     * @return Agent[]
     */
    public function all(): array
    {
        return $this->agents;
    }

    /**
     * 通过系统命令检测当前系统安装了哪些编辑器
     *
     * @return Agent[]
     */
    public function detectOnSystem(): array
    {
        return array_values(array_filter(
            $this->agents,
            fn(Agent $agent): bool => $agent->detectOnSystem()
        ));
    }

    /**
     * 通过项目文件特征检测当前项目使用了哪些编辑器
     *
     * @return Agent[]
     */
    public function detectInProject(string $rootPath): array
    {
        return array_values(array_filter(
            $this->agents,
            fn(Agent $agent): bool => $agent->detectInProject($rootPath)
        ));
    }

    /**
     * 通过名称获取 Agent
     */
    public function findByName(string $name): ?Agent
    {
        foreach ($this->agents as $agent) {
            if ($agent->name() === $name) {
                return $agent;
            }
        }
        return null;
    }

    /**
     * 通过 displayName 获取 Agent
     */
    public function findByDisplayName(string $displayName): ?Agent
    {
        foreach ($this->agents as $agent) {
            if ($agent->displayName() === $displayName) {
                return $agent;
            }
        }
        return null;
    }
}
