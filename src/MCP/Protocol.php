<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP;

/**
 * MCP (Model Context Protocol) 协议常量和消息构建工具
 *
 * 协议版本：2024-11-05
 * 传输层：stdio (JSON-RPC 2.0)
 */
class Protocol
{
    // MCP 协议版本
    public const PROTOCOL_VERSION = '2024-11-05';

    // Server 信息
    public const SERVER_NAME    = 'thinkphp-boost';
    public const SERVER_VERSION = '1.0.0';

    // JSON-RPC 版本
    public const JSONRPC_VERSION = '2.0';

    // MCP 方法名称
    public const METHOD_INITIALIZE          = 'initialize';
    public const METHOD_INITIALIZED         = 'notifications/initialized';
    public const METHOD_TOOLS_LIST          = 'tools/list';
    public const METHOD_TOOLS_CALL          = 'tools/call';
    public const METHOD_PING                = 'ping';
    public const METHOD_CANCEL              = '$/cancelRequest';

    // Prompts 方法
    public const METHOD_PROMPTS_LIST = 'prompts/list';
    public const METHOD_PROMPTS_GET  = 'prompts/get';

    // Resources 方法
    const METHOD_RESOURCES_LIST      = 'resources/list';
    const METHOD_RESOURCES_READ      = 'resources/read';
    const METHOD_RESOURCES_SUBSCRIBE = 'resources/subscribe';

    // JSON-RPC 错误码
    public const ERROR_PARSE_ERROR          = -32700;
    public const ERROR_INVALID_REQUEST      = -32600;
    public const ERROR_METHOD_NOT_FOUND     = -32601;
    public const ERROR_INVALID_PARAMS       = -32602;
    public const ERROR_INTERNAL_ERROR       = -32603;

    /**
     * 构建通用响应
     */
    private static function buildResponse(int|string|null $id, array $result): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id'      => $id,
            'result'  => $result,
        ];
    }

    /**
     * 构建 initialize 响应
     *
     * 协商回客户端请求的协议版本（若不支持则降级到已知最高版本）
     */
    public static function buildInitializeResponse(int|string|null $id, string $instructions = '', string $clientVersion = ''): array
    {
        $knownVersions   = ['2025-03-26', '2024-11-05'];
        $negotiated      = in_array($clientVersion, $knownVersions, true) ? $clientVersion : self::PROTOCOL_VERSION;

        $result = [
            'protocolVersion' => $negotiated,
            'capabilities'    => [
                'tools'     => new \stdClass(),
                'prompts'   => new \stdClass(),
                'resources' => new \stdClass(),
            ],
            'serverInfo' => [
                'name'    => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
        ];

        if ($instructions !== '') {
            $result['instructions'] = $instructions;
        }

        return self::buildResponse($id, $result);
    }

    /**
     * 构建 tools/list 响应
     */
    public static function buildToolsListResponse(int|string|null $id, array $tools): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id'      => $id,
            'result'  => [
                'tools' => $tools,
            ],
        ];
    }

    /**
     * 构建 tools/call 成功响应
     */
    public static function buildToolCallResponse(int|string|null $id, string $text): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id'      => $id,
            'result'  => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
            ],
        ];
    }

    /**
     * 构建错误响应
     */
    public static function buildErrorResponse(int|string|null $id, int $code, string $message, mixed $data = null): array
    {
        $error = [
            'code'    => $code,
            'message' => $message,
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id'      => $id,
            'error'   => $error,
        ];
    }

    /**
     * 构建 pong 响应（响应 ping 请求）
     */
    public static function buildPongResponse(int|string|null $id): array
    {
        return [
            'jsonrpc' => self::JSONRPC_VERSION,
            'id'      => $id,
            'result'  => new \stdClass(),
        ];
    }

    /**
     * 构建 prompts/list 响应
     */
    public static function buildPromptsListResponse(int|string|null $id, array $prompts): array
    {
        return self::buildResponse($id, ['prompts' => $prompts]);
    }

    /**
     * 构建 prompts/get 响应
     */
    public static function buildPromptGetResponse(int|string|null $id, string $description, array $messages): array
    {
        return self::buildResponse($id, [
            'description' => $description,
            'messages'    => $messages,
        ]);
    }

    /**
     * 构建 resources/list 响应
     */
    public static function buildResourcesListResponse(int|string|null $id, array $resources): array
    {
        return self::buildResponse($id, ['resources' => $resources]);
    }

    /**
     * 构建 resources/read 响应
     */
    public static function buildResourceReadResponse(int|string|null $id, string $uri, string $content): array
    {
        return self::buildResponse($id, [
            'contents' => [
                [
                    'uri'      => $uri,
                    'mimeType' => 'text/markdown',
                    'text'     => $content,
                ],
            ],
        ]);
    }

    /**
     * 将响应编码为 JSON 字符串
     *
     * @throws \JsonException
     */
    public static function encode(array $data): string
    {
        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * 解码 JSON-RPC 请求
     *
     * @return array{id: int|string|null, method: string, params: array}
     * @throws \JsonException|\InvalidArgumentException
     */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON-RPC request: not an object');
        }

        if (($data['jsonrpc'] ?? '') !== self::JSONRPC_VERSION) {
            throw new \InvalidArgumentException('Invalid JSON-RPC version');
        }

        $method = $data['method'] ?? null;
        if (!is_string($method) || $method === '') {
            throw new \InvalidArgumentException('Missing or invalid method');
        }

        return [
            'id'     => $data['id'] ?? null,
            'method' => $method,
            'params' => is_array($data['params'] ?? null) ? $data['params'] : [],
        ];
    }
}
