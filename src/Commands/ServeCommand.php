<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\Commands;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use Yangmingzhi\ThinkphpBoost\MCP\Server;
use Yangmingzhi\ThinkphpBoost\MCP\Tools\ToolInterface;

/**
 * ThinkPHP Boost MCP Server 启动命令
 *
 * 使用方式：php think boost:serve
 * 可选参数：--debug 开启调试日志输出到 STDERR
 */
class ServeCommand extends Command
{
    /**
     * 工具配置键 → 工具类名映射表
     * 新增工具只需在此处追加一行，无需修改其他代码
     */
    private const TOOL_MAP = [
        'app_info'         => \Yangmingzhi\ThinkphpBoost\MCP\Tools\AppInfoTool::class,
        'routes'           => \Yangmingzhi\ThinkphpBoost\MCP\Tools\RoutesTool::class,
        'schema'           => \Yangmingzhi\ThinkphpBoost\MCP\Tools\SchemaTool::class,
        'db_connections'   => \Yangmingzhi\ThinkphpBoost\MCP\Tools\DatabaseConnectionsTool::class,
        'commands'         => \Yangmingzhi\ThinkphpBoost\MCP\Tools\CommandsTool::class,
        'logs'             => \Yangmingzhi\ThinkphpBoost\MCP\Tools\LogsTool::class,
        'last_error'       => \Yangmingzhi\ThinkphpBoost\MCP\Tools\LastErrorTool::class,
        'config'           => \Yangmingzhi\ThinkphpBoost\MCP\Tools\ConfigTool::class,
        'execute_sql'      => \Yangmingzhi\ThinkphpBoost\MCP\Tools\ExecuteSqlTool::class,
        'run_php'          => \Yangmingzhi\ThinkphpBoost\MCP\Tools\RunPhpTool::class,
        'format_code'      => \Yangmingzhi\ThinkphpBoost\MCP\Tools\FormatCodeTool::class,
        'get_absolute_url' => \Yangmingzhi\ThinkphpBoost\MCP\Tools\GetAbsoluteUrlTool::class,
    ];

    protected function configure(): void
    {
        $this->setName('boost:serve')
            ->setDescription('Start the ThinkPHP Boost MCP Server')
            ->addOption(
                'debug',
                'd',
                Option::VALUE_NONE,
                '开启调试模式，将详细日志输出到 STDERR'
            );
    }

    protected function execute(Input $input, Output $output): int
    {
        $debug = (bool)$input->getOption('debug');

        fwrite(STDERR, "启动 ThinkPHP Boost MCP Server...\n");

        $app    = $this->app;
        $config = [];

        try {
            $config = (array)$app->config->get('boost', []);
        } catch (\Throwable $e) {
            fwrite(STDERR, "警告：无法读取 boost 配置，使用默认配置。错误：{$e->getMessage()}\n");
        }

        // 默认全部启用
        $toolsConfig = $config['tools'] ?? array_fill_keys(array_keys(self::TOOL_MAP), true);

        $server = new Server($debug);

        // 自动发现并注册工具
        foreach (self::TOOL_MAP as $key => $class) {
            if (!empty($toolsConfig[$key])) {
                $tool = new $class($app);
                if ($tool instanceof ToolInterface) {
                    $server->registerTool($tool);
                    fwrite(STDERR, "已加载工具: {$tool->getName()}\n");
                }
            }
        }

        fwrite(STDERR, "MCP Server 就绪，等待请求（通过 STDIN）...\n");
        fwrite(STDERR, "按 Ctrl+C 停止服务器\n");

        // 加载 Guidelines
        $guidelinesLoader = new \Yangmingzhi\ThinkphpBoost\MCP\GuidelinesLoader($app->getRootPath());
        $server->setInstructions($guidelinesLoader->load());

        // 加载 Prompts
        $promptsRegistry = new \Yangmingzhi\ThinkphpBoost\MCP\PromptsRegistry($app->getRootPath());
        $promptsRegistry->loadAll();
        $server->setPromptsRegistry($promptsRegistry);

        // 加载 Skills
        $skillsRegistry = new \Yangmingzhi\ThinkphpBoost\MCP\SkillsRegistry($app->getRootPath());
        $skillsRegistry->loadAll();
        $server->setSkillsRegistry($skillsRegistry);
        fwrite(STDERR, "Skills 系统已加载（" . count($skillsRegistry->list()) . " 个技能）\n");

        // 启动服务器主循环（此方法不会返回）
        $server->run();
    }
}
