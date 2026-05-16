<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Commands;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use Yangmingzhi\ThinkphpBoost\Install\AgentsDetector;

/**
 * 同步更新 Skills 和 MCP 配置
 *
 * 功能：
 *   1. 检测项目中哪些编辑器已配置 MCP
 *   2. 重新写入最新的 MCP 配置（不覆盖已有 server 条目）
 *   3. 列出当前 Skills 状态
 */
class UpdateCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('boost:update')
            ->setDescription('更新 ThinkPHP Boost 的 MCP 配置和 Skills 状态')
            ->addOption('mcp-only',    null, Option::VALUE_NONE, '仅更新 MCP 配置文件，跳过 Skills 检查')
            ->addOption('skills-only', null, Option::VALUE_NONE, '仅检查 Skills，跳过 MCP 更新');
    }

    protected function execute(Input $input, Output $output): int
    {
        $output->writeln('');
        $output->writeln('<info>╔══════════════════════════════════════╗</info>');
        $output->writeln('<info>║    ThinkPHP Boost · 更新              ║</info>');
        $output->writeln('<info>╚══════════════════════════════════════╝</info>');
        $output->writeln('');

        $rootPath   = $this->app->getRootPath();
        $mcpOnly    = (bool)$input->getOption('mcp-only');
        $skillsOnly = (bool)$input->getOption('skills-only');

        // ── 1. 更新 MCP 配置 ─────────────────────────────────────────
        if (!$skillsOnly) {
            $this->updateMcpConfigs($rootPath, $output);
        }

        // ── 2. 检查 Skills ───────────────────────────────────────────
        if (!$mcpOnly) {
            $this->checkSkills($rootPath, $output);
        }

        $output->writeln('<info>✓ 更新完成！</info>');
        $output->writeln('');

        return 0;
    }

    private function updateMcpConfigs(string $rootPath, Output $output): void
    {
        $output->writeln('<comment>── MCP 配置更新 ──────────────────────────</comment>');

        $detector = new AgentsDetector();

        // 优先检测项目中已有的编辑器配置
        $detected = $detector->detectInProject($rootPath);

        if (empty($detected)) {
            // 没有项目级配置时，尝试系统级检测
            $detected = $detector->detectOnSystem();
        }

        if (empty($detected)) {
            $output->writeln('  <comment>未检测到任何 AI 编辑器，跳过 MCP 配置更新。</comment>');
            $output->writeln('  <comment>如需手动配置，请运行：php think boost:install</comment>');
            $output->writeln('');
            return;
        }

        foreach ($detected as $agent) {
            $configPath = $rootPath . ltrim($agent->mcpConfigPath(), DIRECTORY_SEPARATOR);
            $alreadyExists = file_exists($rootPath . $agent->mcpConfigPath());

            $ok = $agent->writeMcpConfig($rootPath);

            if ($ok) {
                $action = $alreadyExists ? '已检查' : '已生成';
                $output->writeln("  <info>✓</info> {$agent->displayName()}：{$action} {$agent->mcpConfigPath()}");
            } else {
                $output->writeln("  <error>✗</error> {$agent->displayName()}：写入失败 {$agent->mcpConfigPath()}");
            }
        }

        $output->writeln('');
    }

    private function checkSkills(string $rootPath, Output $output): void
    {
        $output->writeln('<comment>── Skills 状态 ────────────────────────────</comment>');

        $skillsDir = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . '.ai' . DIRECTORY_SEPARATOR . 'skills';

        if (!is_dir($skillsDir)) {
            $output->writeln('  <comment>未找到 .ai/skills/ 目录。</comment>');
            $output->writeln('  使用 <comment>php think boost:add-skill owner/repo</comment> 从 GitHub 安装 Skill。');
            $output->writeln('');
            return;
        }

        $dirs = glob($skillsDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];

        if (empty($dirs)) {
            $output->writeln('  <comment>.ai/skills/ 目录为空。</comment>');
            $output->writeln('');
            return;
        }

        $output->writeln(sprintf('  找到 <info>%d</info> 个已安装的 Skill：', count($dirs)));
        foreach ($dirs as $dir) {
            $name      = basename($dir);
            $skillFile = $dir . DIRECTORY_SEPARATOR . 'SKILL.md';
            $status    = is_file($skillFile) ? '<info>✓</info>' : '<comment>⚠ 缺少 SKILL.md</comment>';
            $output->writeln("    {$status} {$name}");
        }

        $output->writeln('');
        $output->writeln('  使用 <comment>php think boost:list-skills</comment> 查看详细信息。');
        $output->writeln('  使用 <comment>php think boost:add-skill owner/repo</comment> 添加更多 Skill。');
        $output->writeln('');
    }
}
