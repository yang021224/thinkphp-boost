<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP\Tools;

use think\App;

/**
 * 路由工具 - 列出 ThinkPHP 应用的所有注册路由
 */
class RoutesTool implements ToolInterface
{
    public function __construct(
        private readonly App $app
    ) {}

    public function getName(): string
    {
        return 'get_routes';
    }

    public function getDescription(): string
    {
        return '列出 ThinkPHP 应用的所有注册路由，包括路由规则、请求方法、控制器和中间件信息';
    }

    public function getInputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(),
            'required'   => [],
        ];
    }

    public function execute(array $params): string
    {
        $routes = $this->collectRoutes();

        if (empty($routes)) {
            return "未找到任何路由定义。\n\n可能的原因：\n1. route/ 目录不存在\n2. 路由文件为空\n3. 未定义任何路由规则";
        }

        $output = "ThinkPHP 路由列表\n";
        $output .= str_repeat('=', 80) . "\n\n";

        $grouped = [];
        foreach ($routes as $route) {
            $grouped[$route['source']][] = $route;
        }

        foreach ($grouped as $source => $sourceRoutes) {
            $output .= "来源文件: {$source}\n";
            $output .= str_repeat('-', 60) . "\n";

            $maxMethod = max(array_map(fn($r) => strlen($r['method']), $sourceRoutes));
            $maxUri    = max(array_map(fn($r) => strlen($r['uri']), $sourceRoutes));

            $output .= sprintf(
                "%-{$maxMethod}s  %-{$maxUri}s  %s\n",
                '方法',
                'URI',
                '处理器'
            );
            $output .= str_repeat('-', 60) . "\n";

            foreach ($sourceRoutes as $route) {
                $middleware = !empty($route['middleware'])
                    ? '  [中间件: ' . implode(', ', $route['middleware']) . ']'
                    : '';

                $output .= sprintf(
                    "%-{$maxMethod}s  %-{$maxUri}s  %s%s\n",
                    $route['method'],
                    $route['uri'],
                    $route['handler'],
                    $middleware
                );
            }

            $output .= "\n";
        }

        $output .= sprintf("共 %d 条路由规则\n", count($routes));

        return $output;
    }

    /**
     * 收集所有路由信息
     */
    private function collectRoutes(): array
    {
        $routes = [];

        // 优先使用 ThinkPHP 路由对象获取已注册路由
        $routes = array_merge($routes, $this->getRoutesFromRouter());

        // 补充从路由文件静态分析的路由
        if (empty($routes)) {
            $routes = array_merge($routes, $this->getRoutesFromFiles());
        }

        return $routes;
    }

    /**
     * 通过 ThinkPHP 路由对象获取路由
     */
    private function getRoutesFromRouter(): array
    {
        $routes = [];

        try {
            /** @var \think\Route $router */
            $router = $this->app->route;

            // 加载路由文件
            $routePath = $this->app->getRootPath() . 'route';
            if (is_dir($routePath)) {
                $files = glob($routePath . DIRECTORY_SEPARATOR . '*.php');
                foreach ($files as $file) {
                    try {
                        include_once $file;
                    } catch (\Throwable $e) {
                        // 部分路由文件可能依赖完整应用上下文，忽略加载错误
                    }
                }
            }

            // 获取所有路由规则
            $ruleList = $router->getRuleList();
            foreach ($ruleList as $rule) {
                $method  = strtoupper($rule['method'] ?? 'ANY');
                $uri     = $rule['rule'] ?? '/';
                $handler = $this->formatHandler($rule['route'] ?? '');
                $middleware = [];

                if (!empty($rule['option']['middleware'])) {
                    $middleware = (array)$rule['option']['middleware'];
                    $middleware = array_map(fn($m) => is_string($m) ? $m : get_class($m), $middleware);
                }

                $routes[] = [
                    'method'     => $method,
                    'uri'        => '/' . ltrim((string)$uri, '/'),
                    'handler'    => $handler,
                    'middleware' => $middleware,
                    'source'     => 'router',
                ];
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "RoutesTool::getRoutesFromRouter 错误: " . $e->getMessage() . "\n");
        }

        return $routes;
    }

    /**
     * 从路由文件静态分析路由
     */
    private function getRoutesFromFiles(): array
    {
        $routes    = [];
        $routePath = $this->app->getRootPath() . 'route';

        if (!is_dir($routePath)) {
            return $routes;
        }

        $files = glob($routePath . DIRECTORY_SEPARATOR . '*.php');
        if (empty($files)) {
            return $routes;
        }

        foreach ($files as $file) {
            $filename     = basename($file);
            $fileContents = file_get_contents($file);
            if ($fileContents === false) {
                continue;
            }

            $fileRoutes = $this->parseRouteFile($fileContents, $filename);
            $routes     = array_merge($routes, $fileRoutes);
        }

        return $routes;
    }

    /**
     * 解析路由文件内容（正则静态分析）
     */
    private function parseRouteFile(string $content, string $filename): array
    {
        $routes = [];

        // 匹配常见路由定义模式
        // Route::get('/path', 'Controller/action')
        // Route::post('/path', [Controller::class, 'method'])
        $pattern = '/Route\s*::\s*(get|post|put|patch|delete|options|any|rule|resource)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([^)]+)\)/i';

        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $method  = strtoupper($match[1]);
                $uri     = '/' . ltrim($match[2], '/');
                $handler = trim(preg_replace('/\s+/', ' ', $match[3]));

                // 清理 handler 字符串
                $handler = preg_replace('/[\'"\\[\\]\\s]/', '', $handler);
                $handler = str_replace(['::class,', '::class'], '', $handler);

                $routes[] = [
                    'method'     => $method === 'RULE' ? 'ANY' : $method,
                    'uri'        => $uri,
                    'handler'    => $handler ?: '(closure)',
                    'middleware' => [],
                    'source'     => $filename,
                ];
            }
        }

        // 匹配 resource 路由
        $resourcePattern = '/Route\s*::\s*resource\s*\(\s*[\'"]([^\'"]+)[\'"]/i';
        if (preg_match_all($resourcePattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $resource = $match[1];
                $resourceRoutes = [
                    ['GET',    "/{$resource}",           'index'],
                    ['POST',   "/{$resource}",           'save'],
                    ['GET',    "/{$resource}/:id",       'read'],
                    ['PUT',    "/{$resource}/:id",       'update'],
                    ['DELETE', "/{$resource}/:id",       'delete'],
                    ['GET',    "/{$resource}/create",    'create'],
                    ['GET',    "/{$resource}/:id/edit",  'edit'],
                ];

                foreach ($resourceRoutes as [$method, $uri, $action]) {
                    $routes[] = [
                        'method'     => $method,
                        'uri'        => $uri,
                        'handler'    => "{$resource}@{$action}",
                        'middleware' => [],
                        'source'     => $filename,
                    ];
                }
            }
        }

        return $routes;
    }

    /**
     * 格式化路由处理器为可读字符串
     */
    private function formatHandler(mixed $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler) && count($handler) === 2) {
            $class  = is_string($handler[0]) ? $handler[0] : get_class($handler[0]);
            $method = $handler[1];
            return "{$class}@{$method}";
        }

        if ($handler instanceof \Closure) {
            return '(Closure)';
        }

        return '(unknown)';
    }
}
