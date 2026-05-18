# ThinkPHP Boost

为 ThinkPHP 8.x 项目提供 MCP (Model Context Protocol) Server 功能，让 AI 工具（Claude Code、Cursor、Windsurf 等）能够理解并操作你的 ThinkPHP 项目。

---

## 简介

ThinkPHP Boost 是一个受 `laravel-boost` 启发的工具包。它在你的 ThinkPHP 项目中内嵌一个 MCP Server，通过 stdio 传输层与 AI 工具通信，让 AI 能够：

- 查询项目所有路由规则
- 查看数据库表结构
- 执行 `php think` 命令（如数据库迁移）
- 读取运行时日志

**技术栈：**
- PHP 8.1+，ThinkPHP 8.x
- 纯 PHP 实现，不依赖 Node.js
- MCP 协议版本：2025-11-25（兼容 2025-06-18、2025-03-26、2024-11-05）
- 传输层：stdio（JSON-RPC 2.0）

---

## 安装

### 1. 通过 Composer 安装

```bash
composer require yangmingzhi/thinkphp-boost
```

ThinkPHP 8.x 会自动发现并注册服务提供者（通过 `composer.json` 中的 `extra.think.services`），无需手动配置。

### 2. 发布配置文件（可选）

配置文件位于包内的 `config/boost.php`，ThinkPHP 会自动加载。如需自定义，复制到项目 `config/` 目录：

```bash
cp vendor/yangmingzhi/thinkphp-boost/config/boost.php config/boost.php
```

### 3. 验证安装

```bash
php think list | grep boost
# 应输出：boost:serve  Start the ThinkPHP Boost MCP Server
```

---

## 配置 AI 工具的 MCP 连接

### Claude Code 配置

在 ThinkPHP **项目根目录**创建 `.mcp.json`：

```json
{
  "mcpServers": {
    "thinkphp-boost": {
      "command": "php",
      "args": ["think", "boost:serve"]
    }
  }
}
```

### Cursor 配置

在 ThinkPHP **项目根目录**创建 `.cursor/mcp.json`：

```json
{
  "mcpServers": {
    "thinkphp-boost": {
      "command": "php",
      "args": ["think", "boost:serve"]
    }
  }
}
```

### Windsurf 配置

在 ThinkPHP **项目根目录**创建 `.windsurf/mcp_config.json`：

```json
{
  "mcpServers": {
    "thinkphp-boost": {
      "command": "php",
      "args": ["think", "boost:serve"]
    }
  }
}
```

### 开启调试模式

在 `args` 中添加 `--debug` 选项，调试信息会输出到 STDERR（不影响 MCP 通信）：

```json
{
  "args": ["think", "boost:serve", "--debug"]
}
```

---

## 可用工具列表

AI 工具连接后，可使用以下 MCP 工具：

### `get_routes`
列出 ThinkPHP 应用的所有注册路由。

**参数：** 无

**返回示例：**
```
ThinkPHP 路由列表
================================================================================

来源文件: router
------------------------------------------------------------
方法    URI                  处理器
------------------------------------------------------------
GET     /api/users           app\controller\UserController@index
POST    /api/users           app\controller\UserController@save
GET     /api/users/:id       app\controller\UserController@read
```

---

### `get_database_schema`
查询数据库表结构，包括字段名、类型、可空性、默认值和注释。

**参数：**
- `table`（可选）：指定表名，不填则返回全部表结构

**使用示例：**
```
// 获取所有表结构
get_database_schema()

// 获取指定表结构
get_database_schema({ "table": "users" })
```

---

### `run_think_command`
执行 `php think` 命令。传入 `command: "list"` 可查看所有可用命令。

**参数：**
- `command`（必填）：命令名称，如 `"migrate"`、`"make:model User"`、`"list"`
- `args`（可选）：额外参数数组

**使用示例：**
```
// 列出所有命令
run_think_command({ "command": "list" })

// 执行迁移
run_think_command({ "command": "migrate" })

// 创建 Model
run_think_command({ "command": "make:model", "args": ["User"] })
```

**安全限制**（默认禁止的命令）：`serve`、`clear`、`optimize`、`build`、`boost:serve`

---

### `get_logs`
读取 ThinkPHP 运行时日志（`runtime/log` 目录）。

**参数：**
- `lines`（可选）：读取行数，默认 100，最多 1000
- `date`（可选）：日期，格式 `YYYYMMDD`，如 `"20240516"`，不填则读取最新日志
- `level`（可选）：过滤日志级别，可选值：`error`、`warning`、`info`、`debug`、`notice`、`sql`

**使用示例：**
```
// 读取最新 50 行日志
get_logs({ "lines": 50 })

// 读取指定日期的错误日志
get_logs({ "date": "20240516", "level": "error" })
```

---

## 配置文件说明

`config/boost.php`：

```php
return [
    // 工具开关
    'tools' => [
        'routes'   => true,   // get_routes 工具
        'schema'   => true,   // get_database_schema 工具
        'commands' => true,   // run_think_command 工具
        'logs'     => true,   // get_logs 工具
    ],

    // 日志配置
    'logs' => [
        'path'      => runtime_path('log'),  // 日志目录
        'max_lines' => 500,                  // 单次最大读取行数
    ],

    // 命令安全配置
    'commands' => [
        'forbidden' => ['serve', 'clear', 'optimize', 'build'],  // 禁止执行的命令
    ],
];
```

---

## 注意事项

1. **PHP 版本要求**：需要 PHP 8.1 或更高版本，利用了枚举、只读属性、命名参数等新特性。

2. **数据库配置**：`get_database_schema` 工具需要项目配置了有效的数据库连接（`.env` 或 `config/database.php`）。若数据库未配置，工具会返回友好的错误提示，不会导致 Server 崩溃。

3. **路由加载**：`get_routes` 工具会尝试加载 `route/` 目录下的路由文件。如果路由文件中有依赖请求上下文的代码（如 `request()`），可能无法完整解析。

4. **安全性**：`run_think_command` 工具内置了命令黑名单机制。请根据实际情况在配置文件中扩展 `forbidden` 列表，防止 AI 执行危险操作。

5. **STDOUT/STDERR 分离**：MCP 协议要求 JSON-RPC 响应必须从 STDOUT 输出，任何日志或调试信息必须写入 STDERR。ThinkPHP Boost 严格遵守此规则，保证与所有 MCP 客户端的兼容性。

6. **进程管理**：`php think boost:serve` 是一个常驻进程，AI 工具负责管理其生命周期。退出 AI 工具时，MCP Server 进程也会随之终止。

---

## License

MIT
