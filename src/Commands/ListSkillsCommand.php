<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Commands;

use think\console\Command;
use think\console\Input;
use think\console\Output;

/**
 * 列出当前项目中所有可用的 Skills
 */
class ListSkillsCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('boost:list-skills')
            ->setDescription('列出当前项目中所有可用的 Skills');
    }

    protected function execute(Input $input, Output $output): int
    {
        $rootPath  = $this->app->getRootPath();
        $skillsDir = $rootPath . '.ai' . DIRECTORY_SEPARATOR . 'skills';

        $output->writeln('');
        $output->writeln('<info>╔══════════════════════════════════════╗</info>');
        $output->writeln('<info>║      ThinkPHP Boost · Skills 列表    ║</info>');
        $output->writeln('<info>╚══════════════════════════════════════╝</info>');
        $output->writeln('');

        if (!is_dir($skillsDir)) {
            $output->writeln('<comment>当前项目没有 Skills 目录（.ai/skills/）</comment>');
            $output->writeln('');
            $output->writeln('使用以下命令从 GitHub 添加 Skill：');
            $output->writeln('  <comment>php think boost:add-skill owner/repo</comment>');
            $output->writeln('');
            return 0;
        }

        $skills = $this->discoverSkills($skillsDir);

        if (empty($skills)) {
            $output->writeln('<comment>.ai/skills/ 目录中没有找到任何有效的 Skill。</comment>');
            $output->writeln('');
            $output->writeln('Skill 目录必须包含 SKILL.md 文件，格式如下：');
            $output->writeln('  .ai/skills/<skill-name>/SKILL.md');
            $output->writeln('');
            return 0;
        }

        $output->writeln(sprintf('找到 <info>%d</info> 个 Skill：', count($skills)));
        $output->writeln('');

        // 表格输出
        $nameWidth = max(20, ...array_map(fn(array $s): int => strlen($s['name']), $skills));
        $header    = sprintf(
            '  %-' . $nameWidth . 's  %-12s  %s',
            'Skill 名称', '来源', '描述'
        );
        $output->writeln($header);
        $output->writeln('  ' . str_repeat('-', $nameWidth + 2 + 12 + 2 + 50));

        foreach ($skills as $skill) {
            $source = $skill['custom'] ? '<comment>自定义</comment>' : '<info>内置</info>';
            $desc   = mb_strlen($skill['description']) > 48
                ? mb_substr($skill['description'], 0, 45) . '...'
                : $skill['description'];

            $output->writeln(sprintf(
                '  %-' . $nameWidth . 's  %-12s  %s',
                $skill['name'],
                $source,
                $desc
            ));
        }

        $output->writeln('');
        $output->writeln('<comment>* 表示自定义 Skill（来自 .ai/skills/）</comment>');
        $output->writeln('');

        return 0;
    }

    /**
     * 扫描 .ai/skills/ 目录，读取每个 Skill 的 SKILL.md frontmatter
     *
     * @return array<int, array{name: string, description: string, custom: bool}>
     */
    private function discoverSkills(string $skillsDir): array
    {
        $skills = [];
        $dirs   = glob($skillsDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);

        if ($dirs === false) {
            return [];
        }

        foreach ($dirs as $dir) {
            $skillFile = $dir . DIRECTORY_SEPARATOR . 'SKILL.md';

            if (!is_file($skillFile)) {
                continue;
            }

            $content     = file_get_contents($skillFile);
            $frontmatter = $this->parseFrontmatter($content ?: '');

            $name = $frontmatter['name'] ?? basename($dir);
            $desc = $frontmatter['description'] ?? '';

            $skills[] = [
                'name'        => $name,
                'description' => $desc,
                'custom'      => true,
            ];
        }

        // 内置 Skills（来自 SkillsRegistry）
        $builtinDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'MCP' . DIRECTORY_SEPARATOR . 'Skills';
        if (is_dir($builtinDir)) {
            foreach (glob($builtinDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
                $skillFile = $dir . DIRECTORY_SEPARATOR . 'SKILL.md';
                if (!is_file($skillFile)) {
                    continue;
                }
                $content     = file_get_contents($skillFile);
                $frontmatter = $this->parseFrontmatter($content ?: '');
                $skills[]    = [
                    'name'        => $frontmatter['name'] ?? basename($dir),
                    'description' => $frontmatter['description'] ?? '',
                    'custom'      => false,
                ];
            }
        }

        usort($skills, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $skills;
    }

    /**
     * 解析 Markdown frontmatter（--- ... --- 格式）
     *
     * @return array<string, string>
     */
    private function parseFrontmatter(string $content): array
    {
        if (!preg_match('/^\s*---\s*\n(.*?)\n---\s*\n/s', $content, $m)) {
            return [];
        }

        $result = [];
        foreach (explode("\n", $m[1]) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $result[trim($key)] = trim($value, " \t\"'");
        }

        return $result;
    }
}
