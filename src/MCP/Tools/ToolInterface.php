<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Tools;

/**
 * MCP 工具接口
 * 所有 MCP 工具必须实现此接口
 */
interface ToolInterface
{
    /**
     * 获取工具名称（MCP 调用时使用的标识符）
     */
    public function getName(): string;

    /**
     * 获取工具描述（展示给 AI 模型的说明）
     */
    public function getDescription(): string;

    /**
     * 获取工具的 JSON Schema 参数定义
     */
    public function getInputSchema(): array;

    /**
     * 执行工具
     *
     * @param array $params 调用参数
     * @return string 执行结果（文本格式）
     */
    public function execute(array $params): string;
}
