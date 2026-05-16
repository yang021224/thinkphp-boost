<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP;

use Yangmingzhi\ThinkphpBoost\MCP\Tools\ToolInterface;
use Yangmingzhi\ThinkphpBoost\MCP\SkillsRegistry;

/**
 * MCP Server 核心
 *
 * 通过 stdio 传输层实现 JSON-RPC 2.0 协议的 MCP Server。
 * 从 STDIN 逐行读取请求，将响应写入 STDOUT，调试信息写入 STDERR。
 */
class Server
{
    /** @var array<string, ToolInterface> 已注册的工具，键为工具名称 */
    private array $tools = [];

    /** @var bool 是否已完成初始化握手 */
    private bool $initialized = false;

    /** @var bool 是否启用调试日志 */
    private bool $debug;

    /** @var PromptsRegistry|null 已注册的提示词注册表 */
    private ?PromptsRegistry $promptsRegistry = null;

    /** @var SkillsRegistry|null 已注册的技能文档注册表 */
    private ?SkillsRegistry $skillsRegistry = null;

    /** @var string MCP instructions（Guidelines 内容） */
    private string $instructions = '';

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    /**
     * 注册一个 MCP 工具
     */
    public function registerTool(ToolInterface $tool): self
    {
        $this->tools[$tool->getName()] = $tool;
        $this->log("已注册工具: {$tool->getName()}");
        return $this;
    }

    /**
     * 批量注册工具
     *
     * @param ToolInterface[] $tools
     */
    public function registerTools(array $tools): self
    {
        foreach ($tools as $tool) {
            $this->registerTool($tool);
        }
        return $this;
    }

    /**
     * 设置 Prompts 注册表
     */
    public function setPromptsRegistry(PromptsRegistry $registry): self
    {
        $this->promptsRegistry = $registry;
        return $this;
    }

    /**
     * 设置 Skills 注册表
     */
    public function setSkillsRegistry(SkillsRegistry $registry): self
    {
        $this->skillsRegistry = $registry;
        return $this;
    }

    /**
     * 设置 MCP instructions（Guidelines 内容）
     */
    public function setInstructions(string $instructions): self
    {
        $this->instructions = $instructions;
        return $this;
    }

    /**
     * 启动 MCP Server 主循环
     * 持续监听 STDIN，处理请求，直到输入流关闭。
     */
    public function run(): never
    {
        $this->log("ThinkPHP Boost MCP Server 启动（协议版本: " . Protocol::PROTOCOL_VERSION . "）");
        $this->log("已注册工具: " . implode(', ', array_keys($this->tools)));
        $this->log("等待来自 STDIN 的请求...");

        // 设置为非缓冲输出，确保每条响应立即发送
        if (function_exists('stream_set_blocking')) {
            stream_set_blocking(STDIN, true);
        }

        while (true) {
            $line = fgets(STDIN);

            // STDIN 关闭（EOF），正常退出
            if ($line === false) {
                $this->log("STDIN 已关闭，服务器退出。");
                exit(0);
            }

            $line = trim($line);

            // 跳过空行
            if ($line === '') {
                continue;
            }

            $this->log("收到请求: {$line}");

            // ob_start 防止工具内部的意外 echo/print 污染 JSON-RPC STDOUT 流
            ob_start();
            try {
                $response = $this->handleRequest($line);
                ob_end_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                $this->log("未捕获异常: {$e->getMessage()}");
                $decoded  = json_decode($line, true);
                $response = Protocol::buildErrorResponse(
                    $decoded['id'] ?? null,
                    Protocol::ERROR_INTERNAL_ERROR,
                    'Unexpected error: ' . $e->getMessage()
                );
            }

            if ($response !== null) {
                $this->sendResponse($response);
            }
        }
    }

    /**
     * 处理单条 JSON-RPC 请求，返回响应数组或 null（通知类消息不需要响应）
     */
    private function handleRequest(string $rawJson): ?array
    {
        // 解析 JSON
        try {
            $request = Protocol::decode($rawJson);
        } catch (\JsonException $e) {
            $this->log("JSON 解析错误: {$e->getMessage()}");
            return Protocol::buildErrorResponse(null, Protocol::ERROR_PARSE_ERROR, 'Parse error: ' . $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            $this->log("无效请求: {$e->getMessage()}");
            return Protocol::buildErrorResponse(null, Protocol::ERROR_INVALID_REQUEST, 'Invalid Request: ' . $e->getMessage());
        }

        $id     = $request['id'];
        $method = $request['method'];
        $params = $request['params'];

        $this->log("处理方法: {$method}（id=" . json_encode($id) . "）");

        // 通知类消息（无 id）不需要返回响应
        $isNotification = !array_key_exists('id', json_decode($rawJson, true));

        return match ($method) {
            Protocol::METHOD_INITIALIZE    => $this->handleInitialize($id, $params),
            Protocol::METHOD_INITIALIZED   => null, // 通知，不需要响应
            Protocol::METHOD_TOOLS_LIST    => $this->handleToolsList($id, $params),
            Protocol::METHOD_TOOLS_CALL    => $this->handleToolsCall($id, $params),
            Protocol::METHOD_PING          => Protocol::buildPongResponse($id),
            Protocol::METHOD_CANCEL        => null, // 取消通知，不需要响应
            Protocol::METHOD_PROMPTS_LIST        => $this->handlePromptsList($id),
            Protocol::METHOD_PROMPTS_GET         => $this->handlePromptsGet($id, $params),
            Protocol::METHOD_RESOURCES_LIST      => $this->handleResourcesList($id),
            Protocol::METHOD_RESOURCES_READ      => $this->handleResourcesRead($id, $params),
            Protocol::METHOD_RESOURCES_SUBSCRIBE => null, // 不支持订阅，忽略
            default                              => $isNotification
                ? null
                : Protocol::buildErrorResponse($id, Protocol::ERROR_METHOD_NOT_FOUND, "Method not found: {$method}"),
        };
    }

    /**
     * 处理 initialize 请求
     */
    private function handleInitialize(int|string|null $id, array $params): array
    {
        $clientName    = $params['clientInfo']['name'] ?? 'unknown';
        $clientVersion = $params['clientInfo']['version'] ?? 'unknown';

        $this->log("客户端信息: {$clientName} v{$clientVersion}");
        $this->initialized = true;

        return Protocol::buildInitializeResponse($id, $this->instructions);
    }

    /**
     * 处理 tools/list 请求
     */
    private function handleToolsList(int|string|null $id, array $params): array
    {
        $toolList = [];

        foreach ($this->tools as $tool) {
            $toolList[] = [
                'name'        => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }

        $this->log("返回 " . count($toolList) . " 个工具");

        return Protocol::buildToolsListResponse($id, $toolList);
    }

    /**
     * 处理 tools/call 请求
     */
    private function handleToolsCall(int|string|null $id, array $params): array
    {
        $toolName  = $params['name'] ?? null;
        $toolInput = $params['arguments'] ?? [];

        if (!is_string($toolName) || $toolName === '') {
            return Protocol::buildErrorResponse(
                $id,
                Protocol::ERROR_INVALID_PARAMS,
                'Missing required parameter: name'
            );
        }

        if (!isset($this->tools[$toolName])) {
            return Protocol::buildErrorResponse(
                $id,
                Protocol::ERROR_METHOD_NOT_FOUND,
                "Tool not found: {$toolName}"
            );
        }

        $tool = $this->tools[$toolName];

        $this->log("执行工具: {$toolName}，参数: " . json_encode($toolInput, JSON_UNESCAPED_UNICODE));

        try {
            $result = $tool->execute(is_array($toolInput) ? $toolInput : []);
            $this->log("工具 {$toolName} 执行完成，结果长度: " . strlen($result));
            return Protocol::buildToolCallResponse($id, $result);
        } catch (\Throwable $e) {
            $this->log("工具 {$toolName} 执行异常: " . $e->getMessage());
            return Protocol::buildErrorResponse(
                $id,
                Protocol::ERROR_INTERNAL_ERROR,
                "Tool execution failed: " . $e->getMessage()
            );
        }
    }

    /**
     * 处理 prompts/list 请求
     */
    private function handlePromptsList(int|string|null $id): array
    {
        if ($this->promptsRegistry === null) {
            return Protocol::buildPromptsListResponse($id, []);
        }

        return Protocol::buildPromptsListResponse($id, $this->promptsRegistry->list());
    }

    /**
     * 处理 prompts/get 请求
     */
    private function handlePromptsGet(int|string|null $id, array $params): array
    {
        $name = $params['name'] ?? '';

        if ($this->promptsRegistry === null) {
            return Protocol::buildErrorResponse($id, Protocol::ERROR_METHOD_NOT_FOUND, "Prompt not found: {$name}");
        }

        $prompt = $this->promptsRegistry->get($name, $params['arguments'] ?? []);

        if ($prompt === null) {
            return Protocol::buildErrorResponse($id, Protocol::ERROR_METHOD_NOT_FOUND, "Prompt not found: {$name}");
        }

        return Protocol::buildPromptGetResponse($id, $prompt['description'], $prompt['messages']);
    }

    /**
     * 处理 resources/list 请求
     */
    private function handleResourcesList(int|string|null $id): array
    {
        if ($this->skillsRegistry === null) {
            return Protocol::buildResourcesListResponse($id, []);
        }
        return Protocol::buildResourcesListResponse($id, $this->skillsRegistry->list());
    }

    /**
     * 处理 resources/read 请求
     */
    private function handleResourcesRead(int|string|null $id, array $params): array
    {
        $uri = $params['uri'] ?? '';

        if ($this->skillsRegistry === null) {
            return Protocol::buildErrorResponse(
                $id,
                Protocol::ERROR_METHOD_NOT_FOUND,
                "Resource not found: {$uri}"
            );
        }

        $content = $this->skillsRegistry->read($uri);

        if ($content === null) {
            return Protocol::buildErrorResponse(
                $id,
                Protocol::ERROR_METHOD_NOT_FOUND,
                "Resource not found: {$uri}"
            );
        }

        return Protocol::buildResourceReadResponse($id, $uri, $content);
    }

    /**
     * 将响应编码为 JSON 并写入 STDOUT
     */
    private function sendResponse(array $response): void
    {
        try {
            $json = Protocol::encode($response);
            $this->log("发送响应: {$json}");

            // 写入 STDOUT，每条响应以换行符结尾
            fwrite(STDOUT, $json . "\n");
            fflush(STDOUT);
        } catch (\JsonException $e) {
            $this->log("响应序列化失败: {$e->getMessage()}");

            // 尝试发送一个最基本的错误响应
            $fallback = '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal serialization error"}}';
            fwrite(STDOUT, $fallback . "\n");
            fflush(STDOUT);
        }
    }

    /**
     * 向 STDERR 写入调试日志
     * 注意：永远不能写入 STDOUT，否则破坏 JSON-RPC 协议
     */
    private function log(string $message): void
    {
        if ($this->debug) {
            $timestamp = date('Y-m-d H:i:s');
            fwrite(STDERR, "[{$timestamp}] [thinkphp-boost] {$message}\n");
        }
    }
}
