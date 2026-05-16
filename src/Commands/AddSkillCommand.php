<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Commands;

use InvalidArgumentException;
use RuntimeException;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use Yangmingzhi\ThinkphpBoost\Skills\Remote\AuditResult;
use Yangmingzhi\ThinkphpBoost\Skills\Remote\GitHubRepository;
use Yangmingzhi\ThinkphpBoost\Skills\Remote\GitHubSkillProvider;
use Yangmingzhi\ThinkphpBoost\Skills\Remote\RemoteSkill;
use Yangmingzhi\ThinkphpBoost\Skills\Remote\Risk;
use Yangmingzhi\ThinkphpBoost\Skills\Remote\SkillAuditor;

/**
 * 从 GitHub 仓库添加 Skill 到当前项目
 */
class AddSkillCommand extends Command
{
    private const DEFAULT_SKILLS_PATH = '.ai/skills';

    protected function configure(): void
    {
        $this->setName('boost:add-skill')
            ->setDescription('从 GitHub 仓库下载并安装 Skill')
            ->addArgument('repo', Argument::OPTIONAL, 'GitHub 仓库地址（owner/repo 或完整 URL）')
            ->addOption('list',       'l', Option::VALUE_NONE, '仅列出可用 Skills，不安装')
            ->addOption('all',        'a', Option::VALUE_NONE, '安装全部 Skills')
            ->addOption('force',      'f', Option::VALUE_NONE, '覆盖已存在的 Skills')
            ->addOption('skip-audit', null, Option::VALUE_NONE, '跳过安全审计');
    }

    protected function execute(Input $input, Output $output): int
    {
        $output->writeln('');
        $output->writeln('<info>╔══════════════════════════════════════╗</info>');
        $output->writeln('<info>║   ThinkPHP Boost · 添加远程 Skill    ║</info>');
        $output->writeln('<info>╚══════════════════════════════════════╝</info>');
        $output->writeln('');

        // ── 1. 解析仓库地址 ─────────────────────────────────────────
        $repoInput = $input->getArgument('repo');

        if (!$repoInput) {
            $repoInput = $output->ask($input, '请输入 GitHub 仓库地址（owner/repo 或完整 URL）：', null);
        }

        try {
            $repository = GitHubRepository::fromInput((string)$repoInput);
        } catch (InvalidArgumentException $e) {
            $output->writeln("<error>仓库地址格式错误：{$e->getMessage()}</error>");
            return 1;
        }

        $output->writeln("  仓库：<info>{$repository->source()}</info>");
        $output->writeln('');

        // ── 2. 发现可用 Skills ───────────────────────────────────────
        $provider = new GitHubSkillProvider($repository);

        $output->writeln('  <comment>正在获取可用 Skills...</comment>');

        try {
            $availableSkills = $provider->discoverSkills();
        } catch (RuntimeException $e) {
            $output->writeln("<error>获取失败：{$e->getMessage()}</error>");
            return 1;
        }

        if (empty($availableSkills)) {
            $output->writeln('<error>该仓库中没有找到任何有效的 Skill（需要包含 SKILL.md 文件）。</error>');
            return 1;
        }

        $output->writeln(sprintf('  找到 <info>%d</info> 个可用 Skill：', count($availableSkills)));
        foreach (array_keys($availableSkills) as $name) {
            $output->writeln("    · {$name}");
        }
        $output->writeln('');

        // ── 3. 仅列出模式 ───────────────────────────────────────────
        if ($input->getOption('list')) {
            return 0;
        }

        // ── 4. 选择要安装的 Skills ──────────────────────────────────
        $selectedSkills = $this->selectSkills($availableSkills, $input, $output);

        if (empty($selectedSkills)) {
            $output->writeln('<comment>未选择任何 Skill，操作取消。</comment>');
            return 0;
        }

        // ── 5. 检查已存在的 Skills ──────────────────────────────────
        $rootPath   = $this->app->getRootPath();
        $skillsRoot = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_SKILLS_PATH);

        $force       = (bool)$input->getOption('force');
        $toInstall   = [];
        $toOverwrite = [];

        foreach ($selectedSkills as $name => $skill) {
            $targetPath = $skillsRoot . DIRECTORY_SEPARATOR . $name;
            if (is_dir($targetPath)) {
                $toOverwrite[$name] = $skill;
            } else {
                $toInstall[$name] = $skill;
            }
        }

        if (!empty($toOverwrite) && !$force) {
            $output->writeln(sprintf(
                '  以下 <comment>%d</comment> 个 Skill 已存在：%s',
                count($toOverwrite),
                implode('、', array_keys($toOverwrite))
            ));
            $overwrite = $output->confirm($input, '  是否覆盖更新？', false);
            if (!$overwrite) {
                $selectedSkills = $toInstall;
                if (empty($selectedSkills)) {
                    $output->writeln('<comment>没有需要安装的新 Skill。</comment>');
                    return 0;
                }
            }
        }

        // ── 6. 安全审计 ─────────────────────────────────────────────
        if (!$input->getOption('skip-audit')) {
            $proceed = $this->runAudit($repository->source(), array_keys($selectedSkills), $output, $input);
            if (!$proceed) {
                return 0;
            }
        }

        // ── 7. 下载安装 ─────────────────────────────────────────────
        $output->writeln('  <comment>正在下载...</comment>');

        $installed = [];
        $failed    = [];

        foreach ($selectedSkills as $name => $skill) {
            $targetPath = $skillsRoot . DIRECTORY_SEPARATOR . $name;

            // 清理旧版本
            if (is_dir($targetPath)) {
                $this->deleteDirectory($targetPath);
            }

            try {
                if ($provider->downloadSkill($skill, $targetPath)) {
                    $installed[] = $name;
                } else {
                    $failed[$name] = '下载失败';
                }
            } catch (RuntimeException $e) {
                $failed[$name] = $e->getMessage();
            }
        }

        $output->writeln('');

        if (!empty($installed)) {
            $output->writeln('<info>✓ 安装成功：</info>');
            foreach ($installed as $name) {
                $output->writeln("    · {$name}");
            }
        }

        if (!empty($failed)) {
            $output->writeln('<error>✗ 安装失败：</error>');
            foreach ($failed as $name => $reason) {
                $output->writeln("    · {$name}：{$reason}");
            }
        }

        $output->writeln('');
        $output->writeln('<info>✓ 完成！重启 AI 工具以加载新 Skill。</info>');
        $output->writeln('');

        return empty($failed) ? 0 : 1;
    }

    /**
     * 选择要安装的 Skills
     *
     * @param  array<string, RemoteSkill>  $available
     * @return array<string, RemoteSkill>
     */
    private function selectSkills(array $available, Input $input, Output $output): array
    {
        if ($input->getOption('all')) {
            return $available;
        }

        $names = array_keys($available);

        $output->writeln('  请选择要安装的 Skill（输入编号，多个用逗号分隔，输入 "all" 安装全部）：');
        foreach ($names as $i => $name) {
            $output->writeln(sprintf('    [%d] %s', $i + 1, $name));
        }
        $output->writeln('');

        $answer = trim((string)$output->ask($input, '  请输入选择', 'all'));

        if (strtolower($answer) === 'all') {
            return $available;
        }

        $selected = [];
        foreach (explode(',', $answer) as $part) {
            $idx = (int)trim($part) - 1;
            if (isset($names[$idx])) {
                $name            = $names[$idx];
                $selected[$name] = $available[$name];
            }
        }

        return $selected;
    }

    /**
     * 执行安全审计，返回是否继续安装
     *
     * @param  string[]  $skillNames
     */
    private function runAudit(string $source, array $skillNames, Output $output, Input $input): bool
    {
        $output->writeln('  <comment>正在进行安全审计...</comment>');

        $auditor = new SkillAuditor();
        $results = $auditor->audit($source, $skillNames);

        if (empty($results)) {
            // 审计服务无响应，默认放行
            return true;
        }

        $hasRisk = false;
        foreach ($results as $skillAudits) {
            foreach ($skillAudits as $audit) {
                if ($audit->risk->weight() >= Risk::Medium->weight()) {
                    $hasRisk = true;
                    break 2;
                }
            }
        }

        if (!$hasRisk) {
            $output->writeln('  <info>✓ 安全审计通过</info>');
            return true;
        }

        $output->writeln('');
        $output->writeln('<comment>⚠ 安全审计发现风险：</comment>');

        foreach ($results as $skill => $audits) {
            foreach ($audits as $audit) {
                if ($audit->risk->weight() >= Risk::Medium->weight()) {
                    $output->writeln(sprintf(
                        '    · %s  [%s] 风险等级：%s',
                        $skill,
                        $audit->partner,
                        $audit->risk->label()
                    ));
                }
            }
        }

        $output->writeln('');

        return $output->confirm($input, '  是否仍要继续安装？', false);
    }

    private function deleteDirectory(string $path): bool
    {
        if (!is_dir($path)) {
            return true;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        return @rmdir($path);
    }
}
