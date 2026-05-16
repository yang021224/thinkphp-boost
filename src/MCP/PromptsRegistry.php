<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP;

/**
 * MCP Prompts 注册表
 *
 * 管理所有预置的 ThinkPHP 提示词，并支持从项目 .ai/prompts/ 目录加载自定义提示词。
 */
class PromptsRegistry
{
    /** @var array<string, array{description: string, arguments: array, messages: array}> */
    private array $prompts = [];

    public function __construct(private readonly string $rootPath) {}

    /**
     * 加载所有提示词（内置 + 自定义）
     */
    public function loadAll(): void
    {
        $this->registerBuiltins();
        $this->loadCustomPrompts();
    }

    /**
     * 返回 prompts/list 格式的列表
     *
     * @return array<int, array{name: string, description: string, arguments: array}>
     */
    public function list(): array
    {
        $result = [];

        foreach ($this->prompts as $name => $prompt) {
            $item = [
                'name'        => $name,
                'description' => $prompt['description'],
                'arguments'   => $prompt['arguments'],
            ];
            $result[] = $item;
        }

        return $result;
    }

    /**
     * 获取指定提示词，支持参数替换
     *
     * @param  array<string, string> $arguments 参数键值对，用于替换消息内容中的 {{key}}
     * @return array{description: string, messages: array}|null
     */
    public function get(string $name, array $arguments = []): ?array
    {
        if (!isset($this->prompts[$name])) {
            return null;
        }

        $prompt   = $this->prompts[$name];
        $messages = $prompt['messages'];

        // 替换消息内容中的模板变量
        if (!empty($arguments)) {
            $messages = array_map(function (array $message) use ($arguments): array {
                $content = $message['content'];
                foreach ($arguments as $key => $value) {
                    $content = str_replace('{{' . $key . '}}', (string)$value, $content);
                }
                $message['content'] = $content;
                return $message;
            }, $messages);
        }

        return [
            'description' => $prompt['description'],
            'messages'    => $messages,
        ];
    }

    /**
     * 注册内置提示词
     */
    private function registerBuiltins(): void
    {
        $this->prompts['thinkphp-code-review'] = [
            'description' => '对当前 ThinkPHP 代码进行规范审查',
            'arguments'   => [],
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => '请对以下代码进行 ThinkPHP 8.x 最佳实践审查，检查：1) 是否遵循 ThinkPHP 命名规范；2) 模型关联是否正确使用；3) 查询构建器是否有 N+1 问题；4) 是否正确使用了依赖注入；5) 异常处理是否完善。',
                ],
            ],
        ];

        $this->prompts['thinkphp-debug-error'] = [
            'description' => '分析并修复 ThinkPHP 应用错误',
            'arguments'   => [],
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => '请先调用 get_last_error 工具获取最近的错误信息，然后分析错误原因，并给出修复方案。',
                ],
            ],
        ];

        $this->prompts['thinkphp-create-crud'] = [
            'description' => '为指定资源创建完整的 CRUD（控制器、模型、路由）',
            'arguments'   => [
                [
                    'name'        => 'resource',
                    'description' => '资源名称，如 User、Article',
                    'required'    => true,
                ],
            ],
            'messages' => [
                [
                    'role'    => 'user',
                    'content' => '请为 {{resource}} 资源创建完整的 CRUD，包括：1) app/model/{{resource}}.php 模型；2) app/controller/{{resource}}Controller.php 控制器（含 index/save/read/update/delete）；3) 在 route/app.php 中添加 RESTful 路由。遵循 ThinkPHP 8.x 规范。',
                ],
            ],
        ];

        $this->prompts['thinkphp-optimize-query'] = [
            'description' => '分析并优化数据库查询性能',
            'arguments'   => [],
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => '请先调用 get_database_schema 了解表结构，然后分析代码中的数据库查询，找出潜在的性能问题（N+1、缺少索引、全表扫描等），给出优化建议和改写后的代码。',
                ],
            ],
        ];

        $this->prompts['thinkphp-explain-code'] = [
            'description' => '解释 ThinkPHP 项目结构和代码逻辑',
            'arguments'   => [],
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => '请先调用 get_app_info 和 get_routes 了解项目基本情况，然后向我解释这个 ThinkPHP 项目的整体结构、主要模块和业务逻辑。',
                ],
            ],
        ];
    }

    /**
     * 从 .ai/prompts/*.md 加载自定义提示词
     *
     * 文件 frontmatter 格式：
     * ---
     * name: prompt-name
     * description: 提示词描述
     * ---
     * 提示词内容
     */
    private function loadCustomPrompts(): void
    {
        $promptsDir = rtrim($this->rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.ai' . DIRECTORY_SEPARATOR . 'prompts';

        if (!is_dir($promptsDir)) {
            return;
        }

        $files = glob($promptsDir . DIRECTORY_SEPARATOR . '*.md');

        if ($files === false || empty($files)) {
            return;
        }

        sort($files);

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if ($content === false || $content === '') {
                continue;
            }

            $parsed = $this->parseFrontmatter($content);

            if ($parsed === null) {
                continue;
            }

            ['name' => $name, 'description' => $description, 'body' => $body] = $parsed;

            if ($name === '') {
                continue;
            }

            $this->prompts[$name] = [
                'description' => $description,
                'arguments'   => [],
                'messages'    => [
                    [
                        'role'    => 'user',
                        'content' => trim($body),
                    ],
                ],
            ];
        }
    }

    /**
     * 解析 Markdown frontmatter
     *
     * @return array{name: string, description: string, body: string}|null
     */
    private function parseFrontmatter(string $content): ?array
    {
        if (!str_starts_with($content, '---')) {
            return null;
        }

        $end = strpos($content, '---', 3);

        if ($end === false) {
            return null;
        }

        $frontmatter = substr($content, 3, $end - 3);
        $body        = substr($content, $end + 3);

        $name        = '';
        $description = '';

        foreach (explode("\n", $frontmatter) as $line) {
            $line = trim($line);

            if (str_starts_with($line, 'name:')) {
                $name = trim(substr($line, 5));
            } elseif (str_starts_with($line, 'description:')) {
                $description = trim(substr($line, 12));
            }
        }

        return ['name' => $name, 'description' => $description, 'body' => $body];
    }
}
