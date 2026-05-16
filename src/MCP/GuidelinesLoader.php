<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP;

/**
 * MCP Guidelines 加载器
 *
 * 从项目 .ai/guidelines/ 目录加载开发规范，合并后作为 MCP instructions 传递给 AI 客户端。
 * 如果目录不存在或为空，返回内置的 ThinkPHP 开发规范说明。
 */
class GuidelinesLoader
{
    public function __construct(private readonly string $rootPath) {}

    /**
     * 加载 Guidelines
     *
     * 读取 .ai/guidelines/ 目录下所有 .md 文件，按文件名字母顺序合并。
     * 每个文件前加 "## {文件名（去掉.md）}\n\n" 作为分隔标题。
     * 如果目录不存在或为空，返回默认的 ThinkPHP 开发规范说明。
     */
    public function load(): string
    {
        $guidelinesDir = rtrim($this->rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.ai' . DIRECTORY_SEPARATOR . 'guidelines';

        if (!is_dir($guidelinesDir)) {
            return $this->defaultGuidelines();
        }

        $files = glob($guidelinesDir . DIRECTORY_SEPARATOR . '*.md');

        if ($files === false || empty($files)) {
            return $this->defaultGuidelines();
        }

        sort($files);

        $sections = [];

        foreach ($files as $file) {
            $content = file_get_contents($file);

            if ($content === false || trim($content) === '') {
                continue;
            }

            $basename  = basename($file, '.md');
            $sections[] = '## ' . $basename . "\n\n" . trim($content);
        }

        if (empty($sections)) {
            return $this->defaultGuidelines();
        }

        return implode("\n\n", $sections);
    }

    /**
     * 返回内置的默认 Guidelines
     */
    private function defaultGuidelines(): string
    {
        return <<<'GUIDELINES'
你正在协助开发一个 ThinkPHP 8.x 应用。请遵循以下规范：

## ThinkPHP 8.x 核心规范

**目录结构**
- 控制器位于 app/controller/，命名为 XxxController.php
- 模型位于 app/model/，直接继承 \think\Model
- 服务层位于 app/service/（推荐）
- 路由定义在 route/app.php

**模型规范**
- 模型类名与表名遵循自动映射（UserProfile -> user_profile）
- 关联关系使用 hasOne/hasMany/belongsTo/belongsToMany
- 避免在控制器中直接写复杂查询，封装到模型或服务层

**控制器规范**
- 控制器方法返回 json() 或 view()
- 参数验证使用 validate() 方法或独立验证器类
- 不要在控制器中写业务逻辑，调用 Service 层

**数据库规范**
- 优先使用模型操作，复杂查询用查询构建器
- 注意 N+1 问题，使用 with() 预加载关联
- 敏感操作使用事务 Db::transaction()

**命名规范**
- 类名：大驼峰 UserController
- 方法名：小驼峰 getUserInfo
- 数据库字段：下划线 user_name
GUIDELINES;
    }
}
