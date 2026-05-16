<?php

declare(strict_types=1);

namespace Yangmingzhi\ThinkphpBoost\MCP;

/**
 * MCP Skills 注册表
 *
 * 通过 MCP Resources 协议按需向 AI 提供 ThinkPHP 开发知识。
 * Resource URI 格式：thinkphp://skills/{skill-name}
 */
class SkillsRegistry
{
    /** @var array<string, array{name: string, description: string, content: string}> */
    private array $builtins = [];

    /** @var array<string, array{name: string, description: string, content: string}> */
    private array $custom = [];

    public function __construct(private readonly string $rootPath) {}

    /**
     * 加载所有 skills（内置 + 自定义）
     */
    public function loadAll(): void
    {
        $this->registerBuiltins();
        $this->loadCustomSkills();
    }

    /**
     * 返回 MCP resources/list 格式的数组
     *
     * @return array<int, array{uri: string, name: string, description: string, mimeType: string}>
     */
    public function list(): array
    {
        $result = [];

        foreach ($this->builtins as $name => $skill) {
            $result[] = [
                'uri'         => 'thinkphp://skills/' . $name,
                'name'        => $skill['name'],
                'description' => $skill['description'],
                'mimeType'    => 'text/markdown',
            ];
        }

        foreach ($this->custom as $name => $skill) {
            $result[] = [
                'uri'         => 'thinkphp://skills/' . $name,
                'name'        => $skill['name'],
                'description' => $skill['description'],
                'mimeType'    => 'text/markdown',
            ];
        }

        return $result;
    }

    /**
     * 根据 URI 返回 skill 内容，找不到返回 null
     */
    public function read(string $uri): ?string
    {
        $name = $this->uriToName($uri);

        if ($name === null) {
            return null;
        }

        if (isset($this->builtins[$name])) {
            return $this->builtins[$name]['content'];
        }

        if (isset($this->custom[$name])) {
            return $this->custom[$name]['content'];
        }

        return null;
    }

    /**
     * 注册 5 个内置 skills
     */
    private function registerBuiltins(): void
    {
        // ----------------------------------------------------------------
        // 1. think-model
        // ----------------------------------------------------------------
        $this->builtins['think-model'] = [
            'name'        => 'think-model',
            'description' => 'ThinkPHP 模型定义、关联关系、查询构建器最佳实践',
            'content'     => <<<'MARKDOWN'
# ThinkPHP 模型开发技能

## 基础模型定义

模型文件位于 `app/model/`，继承 `\think\Model`：

```php
namespace app\model;

use think\Model;

class User extends Model
{
    // 表名（不含前缀），默认自动映射（User -> user）
    protected $table = 'user';

    // 允许批量赋值的字段
    protected $field = ['username', 'email', 'status'];

    // 只读字段（不允许修改）
    protected $readonly = ['username'];

    // 字段类型转换
    protected $type = [
        'status'     => 'integer',
        'created_at' => 'datetime',
        'extra'      => 'json',
    ];

    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'created_at';
    protected $updateTime = 'updated_at';

    // 软删除
    use \think\model\concern\SoftDelete;
    protected $deleteTime = 'deleted_at';
}
```

## CRUD 操作

```php
// 创建
$user = User::create(['username' => 'Tom', 'email' => 'tom@example.com']);

// 查询单条
$user = User::find(1);
$user = User::where('email', 'tom@example.com')->find();
$user = User::findOrFail(1); // 不存在抛出异常

// 查询多条
$users = User::where('status', 1)->order('created_at', 'desc')->limit(10)->select();
$users = User::where('status', 1)->paginate(15); // 分页

// 更新
$user->username = 'Jerry';
$user->save();
// 或
User::where('id', 1)->update(['username' => 'Jerry']);

// 删除
$user->delete();
User::destroy(1);
```

## 关联关系

```php
class User extends Model
{
    // 一对一：用户有一个 Profile
    public function profile(): \think\model\relation\HasOne
    {
        return $this->hasOne(Profile::class, 'user_id');
    }

    // 一对多：用户有多篇 Article
    public function articles(): \think\model\relation\HasMany
    {
        return $this->hasMany(Article::class, 'user_id');
    }

    // 反向一对一/多：Article 属于 User
    public function user(): \think\model\relation\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // 多对多：用户有多个 Role
    public function roles(): \think\model\relation\BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role', 'user_id', 'role_id');
    }
}

// 预加载（避免 N+1）
$users = User::with(['profile', 'articles'])->select();
$users = User::with(['articles' => function($query) {
    $query->where('status', 1)->limit(5);
}])->select();
```

## 查询构建器

```php
// 链式操作
User::where('status', 1)
    ->where('age', '>', 18)
    ->whereIn('role_id', [1, 2, 3])
    ->whereLike('username', '%tom%')
    ->whereNull('deleted_at')
    ->order('created_at', 'desc')
    ->field('id,username,email')
    ->limit(10, 0)
    ->select();

// 聚合
User::where('status', 1)->count();
User::where('status', 1)->sum('score');
User::max('score');

// 原生查询（谨慎使用）
User::whereRaw('FIND_IN_SET(1, role_ids)')->select();
```

## 模型事件与观察者

```php
class User extends Model
{
    protected static function onBeforeWrite(User $user): void
    {
        $user->username = strtolower($user->username);
    }

    protected static function onAfterCreate(User $user): void
    {
        // 创建后发送欢迎邮件等
    }
}
```

## 常见错误

- **N+1 问题**：循环中访问关联属性时，必须用 `with()` 预加载
- **批量赋值**：未在 `$field` 中声明的字段无法批量写入
- **软删除**：使用软删除后，普通查询自动过滤已删除数据
MARKDOWN,
        ];

        // ----------------------------------------------------------------
        // 2. think-validation
        // ----------------------------------------------------------------
        $this->builtins['think-validation'] = [
            'name'        => 'think-validation',
            'description' => 'ThinkPHP 验证器定义、场景验证、控制器中使用验证的规范',
            'content'     => <<<'MARKDOWN'
# ThinkPHP 验证器开发技能

## 独立验证器类定义

验证器文件位于 `app/validate/`，继承 `\think\Validate`：

```php
namespace app\validate;

use think\Validate;

class UserValidate extends Validate
{
    // 验证规则
    protected $rule = [
        'username' => 'require|max:50|min:3|alphaDash',
        'email'    => 'require|email|unique:user',
        'password' => 'require|min:6|max:32',
        'age'      => 'integer|between:1,120',
        'phone'    => 'regex:/^1[3-9]\d{9}$/',
        'status'   => 'in:0,1',
    ];

    // 自定义错误消息
    protected $message = [
        'username.require'  => '用户名不能为空',
        'username.max'      => '用户名最多 50 个字符',
        'username.min'      => '用户名最少 3 个字符',
        'email.require'     => '邮箱不能为空',
        'email.email'       => '邮箱格式不正确',
        'email.unique'      => '邮箱已被注册',
        'password.require'  => '密码不能为空',
        'password.min'      => '密码最少 6 位',
        'phone.regex'       => '手机号格式不正确',
    ];

    // 场景定义
    protected $scene = [
        'create' => ['username', 'email', 'password'],
        'update' => ['username', 'email'],
        'login'  => ['email', 'password'],
    ];
}
```

## 控制器中使用验证

```php
namespace app\controller;

use app\validate\UserValidate;
use think\exception\ValidateException;

class UserController extends BaseController
{
    // 方式一：使用 validate() 助手方法（推荐）
    public function save(Request $request): Response
    {
        try {
            $this->validate($request->post(), 'app\validate\UserValidate.create');
        } catch (ValidateException $e) {
            return $this->error($e->getError());
        }
        // ...
    }

    // 方式二：实例化后手动检查
    public function update(Request $request, int $id): Response
    {
        $validator = new UserValidate();

        if (!$validator->scene('update')->check($request->post())) {
            return $this->error($validator->getError());
        }
        // ...
    }
}
```

## 自定义验证规则

```php
class UserValidate extends Validate
{
    // 自定义规则方法：checkXxx
    protected function checkUsername(mixed $value, string $rule, array $data): bool|string
    {
        if (str_contains($value, 'admin')) {
            return '用户名不能包含 admin';
        }
        return true;
    }

    protected $rule = [
        'username' => 'require|checkUsername',
    ];
}
```

## 内联验证（不使用独立验证器类）

```php
use think\facade\Validate;

$validate = Validate::rule([
    'name'  => 'require|max:20',
    'email' => 'require|email',
]);

if (!$validate->check(['name' => 'Tom', 'email' => 'tom@example.com'])) {
    // 验证失败
    $error = $validate->getError();
}
```

## 常见规则速查

| 规则 | 说明 |
|------|------|
| `require` | 必填 |
| `max:n` | 最大长度/值为 n |
| `min:n` | 最小长度/值为 n |
| `between:a,b` | 范围 a~b |
| `email` | 邮箱格式 |
| `url` | URL 格式 |
| `integer` | 整数 |
| `float` | 浮点数 |
| `alpha` | 纯字母 |
| `alphaNum` | 字母+数字 |
| `alphaDash` | 字母+数字+下划线+破折号 |
| `unique:table` | 数据库唯一 |
| `exists:table,field` | 数据库中存在 |
| `in:a,b,c` | 枚举值 |
| `regex:/pattern/` | 正则匹配 |
| `date` | 日期格式 |
| `confirm` | 与另一字段相同（常用于密码确认） |
MARKDOWN,
        ];

        // ----------------------------------------------------------------
        // 3. think-middleware
        // ----------------------------------------------------------------
        $this->builtins['think-middleware'] = [
            'name'        => 'think-middleware',
            'description' => 'ThinkPHP 中间件定义、注册、前置/后置中间件、路由中间件的使用规范',
            'content'     => <<<'MARKDOWN'
# ThinkPHP 中间件开发技能

## 中间件类结构

中间件文件位于 `app/middleware/`：

```php
namespace app\middleware;

use think\Request;
use think\Response;

class Auth
{
    public function handle(Request $request, \Closure $next): Response
    {
        // 前置逻辑（在控制器执行前）
        $token = $request->header('Authorization');

        if (empty($token)) {
            return json(['code' => 401, 'msg' => '未授权'], 401);
        }

        // 执行下一个中间件或控制器
        $response = $next($request);

        // 后置逻辑（在控制器执行后）
        // 可以修改响应内容

        return $response;
    }
}
```

## 前置中间件 vs 后置中间件

```php
class LogMiddleware
{
    public function handle(Request $request, \Closure $next): Response
    {
        // === 前置中间件：$next() 之前执行 ===
        $startTime = microtime(true);
        $path = $request->pathinfo();

        $response = $next($request);

        // === 后置中间件：$next() 之后执行 ===
        $duration = microtime(true) - $startTime;
        \think\facade\Log::info("$path 耗时 {$duration}s");

        return $response;
    }
}
```

## 全局中间件注册

在 `app/middleware.php` 中注册对所有请求生效的中间件：

```php
// app/middleware.php
return [
    \app\middleware\LogMiddleware::class,
    \app\middleware\Cors::class,
];
```

## 路由中间件

```php
// route/app.php
use think\facade\Route;
use app\middleware\Auth;
use app\middleware\RateLimit;

// 单个路由
Route::get('user/:id', 'UserController/read')->middleware(Auth::class);

// 路由分组（推荐）
Route::group('api', function () {
    Route::resource('users', 'UserController');
    Route::resource('articles', 'ArticleController');
})->middleware([Auth::class, RateLimit::class]);

// 传参给中间件
Route::get('admin/dashboard', 'AdminController/index')
    ->middleware(Auth::class, 'admin');
```

## 控制器中间件

```php
namespace app\controller;

use think\Request;
use app\middleware\Auth;

class UserController extends BaseController
{
    // 控制器级中间件
    protected array $middleware = [
        Auth::class,
        // 仅对特定方法生效
        // ['middleware' => Auth::class, 'only' => ['save', 'update', 'delete']],
        // ['middleware' => Auth::class, 'except' => ['index', 'read']],
    ];
}
```

## 常见中间件示例

### JWT 鉴权中间件

```php
namespace app\middleware;

use think\Request;
use think\Response;

class JwtAuth
{
    public function handle(Request $request, \Closure $next): Response
    {
        $token = $request->header('Authorization', '');
        $token = ltrim($token, 'Bearer ');

        try {
            $payload = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key(env('JWT_SECRET'), 'HS256'));
            $request->userId = $payload->sub;
        } catch (\Throwable $e) {
            return json(['code' => 401, 'msg' => 'Token 无效或已过期'], 401);
        }

        return $next($request);
    }
}
```

### CORS 跨域中间件

```php
namespace app\middleware;

use think\Request;
use think\Response;

class Cors
{
    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);

        $response->header([
            'Access-Control-Allow-Origin'      => $request->header('origin', '*'),
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, DELETE, OPTIONS',
            'Access-Control-Allow-Headers'     => 'Content-Type, Authorization, X-Requested-With',
            'Access-Control-Allow-Credentials' => 'true',
        ]);

        if ($request->method() === 'OPTIONS') {
            return Response::create('', 'html', 204)->header($response->getHeader());
        }

        return $response;
    }
}
```

## 中间件参数传递

```php
// 中间件定义接收额外参数
class Role
{
    public function handle(Request $request, \Closure $next, string $role): Response
    {
        if ($request->userRole !== $role) {
            return json(['code' => 403, 'msg' => '权限不足'], 403);
        }
        return $next($request);
    }
}

// 路由中传参
Route::get('admin/users', 'AdminController/users')->middleware(Role::class, 'admin');
```
MARKDOWN,
        ];

        // ----------------------------------------------------------------
        // 4. think-multi-app
        // ----------------------------------------------------------------
        $this->builtins['think-multi-app'] = [
            'name'        => 'think-multi-app',
            'description' => 'ThinkPHP 8.x 多应用模式的目录结构、路由配置、跨应用调用规范',
            'content'     => <<<'MARKDOWN'
# ThinkPHP 多应用模式技能

## 安装多应用扩展

```bash
composer require topthink/think-multi-app
```

## 多应用目录结构

```
app/
├── admin/                  # 后台管理应用
│   ├── controller/
│   │   └── Index.php
│   ├── model/
│   ├── middleware/
│   │   └── AdminAuth.php
│   ├── route/
│   │   └── app.php
│   └── provider.php        # 应用级服务绑定（可选）
│
├── api/                    # API 应用
│   ├── controller/
│   │   ├── v1/
│   │   │   └── UserController.php
│   │   └── BaseController.php
│   ├── middleware/
│   │   └── JwtAuth.php
│   └── route/
│       └── app.php
│
└── index/                  # 前台应用（默认）
    ├── controller/
    └── route/
        └── app.php
```

## 开启多应用配置

在 `config/app.php` 中：

```php
return [
    // 开启多应用模式
    'auto_multi_app' => true,

    // 默认应用
    'default_app' => 'index',

    // 应用别名（URL 中的路径 => 应用目录名）
    'app_map' => [
        'admin' => 'admin',
        'api'   => 'api',
    ],

    // 域名绑定应用（可选）
    'domain_bind' => [
        'admin.example.com' => 'admin',
        'api.example.com'   => 'api',
    ],
];
```

## URL 访问规则

```
# 默认格式：/应用名/控制器/方法
/admin/index/index       -> app\admin\controller\Index::index()
/api/user/list           -> app\api\controller\User::list()
/index/home/index        -> app\index\controller\Home::index()

# 子控制器
/admin/user/info/read    -> app\admin\controller\user\Info::read()
```

## 各应用独立路由

`app/admin/route/app.php`：

```php
use think\facade\Route;

// 后台路由（通常在 admin 应用下已无需 /admin 前缀）
Route::group('', function () {
    Route::get('dashboard', 'Index/dashboard');
    Route::resource('users', 'UserController');
})->middleware(\app\admin\middleware\AdminAuth::class);
```

`app/api/route/app.php`：

```php
use think\facade\Route;

// API 版本路由
Route::group('v1', function () {
    Route::resource('users', 'v1\UserController');
    Route::resource('articles', 'v1\ArticleController');
})->middleware(\app\api\middleware\JwtAuth::class);
```

## 跨应用调用

```php
// 方式一：实例化其他应用的模型（模型通常共享）
use app\index\model\User;
$user = User::find(1);

// 方式二：通过命名空间直接调用其他应用的类
$service = new \app\api\service\UserService();

// 方式三：公共模型建议放在独立目录
// app/common/model/User.php  ->  namespace app\common\model;
use app\common\model\User;
```

## 公共层（common 应用）

```
app/
├── common/             # 跨应用公共层
│   ├── model/          # 共享模型
│   ├── service/        # 共享服务
│   └── exception/      # 共享异常处理
└── common.php          # 公共函数（自动加载）
```

## 每个应用独立中间件

`app/admin/middleware.php`（仅对 admin 应用生效）：

```php
return [
    \app\admin\middleware\AdminAuth::class,
];
```

## 常见注意事项

- **模型建议共用**：放在 `app/common/model/` 或某个主应用下，避免重复定义
- **路由文件**：每个应用的 `route/app.php` 只对该应用有效
- **配置文件**：每个应用可有独立的 `config/` 目录，会覆盖全局配置
- **视图路径**：默认为 `app/{app}/view/`
MARKDOWN,
        ];

        // ----------------------------------------------------------------
        // 5. think-api
        // ----------------------------------------------------------------
        $this->builtins['think-api'] = [
            'name'        => 'think-api',
            'description' => 'ThinkPHP RESTful API 开发规范，包括响应格式统一、异常处理、JWT 鉴权',
            'content'     => <<<'MARKDOWN'
# ThinkPHP API 开发技能

## 统一响应格式

在 `app/controller/BaseController.php` 中封装响应方法：

```php
namespace app\controller;

use think\App;
use think\Request;
use think\Response;

abstract class BaseController
{
    protected Request $request;
    protected App $app;

    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $app->request;
        $this->initialize();
    }

    protected function initialize(): void {}

    /**
     * 成功响应
     * @param mixed $data
     */
    protected function success(mixed $data = null, string $msg = 'ok', int $code = 0): Response
    {
        return json([
            'code' => $code,
            'msg'  => $msg,
            'data' => $data,
        ]);
    }

    /**
     * 失败响应
     */
    protected function error(string $msg = 'error', int $code = -1, int $httpCode = 200): Response
    {
        return json([
            'code' => $code,
            'msg'  => $msg,
            'data' => null,
        ], $httpCode);
    }

    /**
     * 分页响应
     * @param \think\Paginator $paginator
     */
    protected function paginate(\think\Paginator $paginator): Response
    {
        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'list'     => $paginator->items(),
                'total'    => $paginator->total(),
                'per_page' => $paginator->listRows(),
                'page'     => $paginator->currentPage(),
                'pages'    => $paginator->lastPage(),
            ],
        ]);
    }
}
```

## RESTful 控制器

```php
namespace app\api\controller;

use app\model\User;
use think\Request;
use think\Response;

class UserController extends BaseController
{
    // GET /api/users  -> 列表
    public function index(Request $request): Response
    {
        $users = User::where('status', 1)
            ->order('created_at', 'desc')
            ->paginate((int)$request->param('per_page', 15));

        return $this->paginate($users);
    }

    // POST /api/users  -> 创建
    public function save(Request $request): Response
    {
        $this->validate($request->post(), 'app\validate\UserValidate.create');

        $user = User::create($request->post(['username', 'email', 'password']));

        return $this->success($user, '创建成功');
    }

    // GET /api/users/:id  -> 详情
    public function read(int $id): Response
    {
        $user = User::findOrFail($id);
        return $this->success($user);
    }

    // PUT /api/users/:id  -> 更新
    public function update(Request $request, int $id): Response
    {
        $user = User::findOrFail($id);
        $this->validate($request->post(), 'app\validate\UserValidate.update');
        $user->save($request->post(['username', 'email']));

        return $this->success($user, '更新成功');
    }

    // DELETE /api/users/:id  -> 删除
    public function delete(int $id): Response
    {
        $user = User::findOrFail($id);
        $user->delete();

        return $this->success(null, '删除成功');
    }
}
```

## 路由配置

```php
// route/app.php
use think\facade\Route;

// API 版本分组
Route::group('api/v1', function () {
    // RESTful 资源路由（自动映射 index/save/read/update/delete）
    Route::resource('users', 'UserController');
    Route::resource('articles', 'ArticleController');

    // 自定义路由
    Route::post('auth/login', 'AuthController/login');
    Route::post('auth/logout', 'AuthController/logout');
    Route::get('auth/me', 'AuthController/me');

})->middleware([\app\middleware\JwtAuth::class]);

// 不需要鉴权的路由放在分组外
Route::post('api/v1/auth/register', 'AuthController/register');
```

## 全局异常处理

`app/exception/Handle.php`：

```php
namespace app\exception;

use think\db\exception\ModelNotFoundException;
use think\exception\Handle as ExceptionHandle;
use think\exception\HttpException;
use think\exception\ValidateException;
use think\Request;
use think\Response;
use Throwable;

class Handle extends ExceptionHandle
{
    // 不需要上报的异常类型
    protected $ignoreReport = [
        HttpException::class,
        ModelNotFoundException::class,
        ValidateException::class,
    ];

    public function render(Request $request, Throwable $e): Response
    {
        // 验证异常
        if ($e instanceof ValidateException) {
            return json(['code' => 422, 'msg' => $e->getError(), 'data' => null], 422);
        }

        // 模型不存在
        if ($e instanceof ModelNotFoundException) {
            return json(['code' => 404, 'msg' => '资源不存在', 'data' => null], 404);
        }

        // HTTP 异常（如 404、403）
        if ($e instanceof HttpException) {
            return json([
                'code' => $e->getStatusCode(),
                'msg'  => $e->getMessage() ?: '请求错误',
                'data' => null,
            ], $e->getStatusCode());
        }

        // 生产环境隐藏详细错误
        if (!app()->isDebug()) {
            return json(['code' => 500, 'msg' => '服务器内部错误', 'data' => null], 500);
        }

        return parent::render($request, $e);
    }
}
```

在 `app/provider.php` 中绑定异常处理类：

```php
return [
    'think\exception\Handle' => \app\exception\Handle::class,
];
```

## 请求参数获取

```php
// 获取所有参数（GET + POST 合并）
$params = $request->param();

// 获取指定参数（带默认值）
$page = $request->param('page', 1);
$name = $request->param('name', '');

// 过滤获取指定字段（白名单）
$data = $request->param(['username', 'email', 'password']);

// 获取 POST 数据
$data = $request->post();

// 获取 JSON 请求体
$data = $request->post();  // ThinkPHP 自动解析 Content-Type: application/json

// 获取请求头
$token = $request->header('Authorization');

// 文件上传
$file = $request->file('avatar');
```

## API 限流中间件示例

```php
namespace app\middleware;

use think\Cache;
use think\Request;
use think\Response;

class RateLimit
{
    public function __construct(private Cache $cache) {}

    public function handle(Request $request, \Closure $next, int $limit = 60, int $window = 60): Response
    {
        $key   = 'rate_limit:' . $request->ip() . ':' . $request->pathinfo();
        $count = (int)$this->cache->get($key, 0);

        if ($count >= $limit) {
            return json(['code' => 429, 'msg' => '请求过于频繁，请稍后再试', 'data' => null], 429);
        }

        $this->cache->set($key, $count + 1, $window);

        $response = $next($request);

        // 在响应头中返回限流信息
        return $response->header([
            'X-RateLimit-Limit'     => $limit,
            'X-RateLimit-Remaining' => max(0, $limit - $count - 1),
        ]);
    }
}
```

## 常见注意事项

- **Content-Type**：客户端发送 JSON 时需设置 `Content-Type: application/json`
- **HTTP 方法**：ThinkPHP 需在 `config/route.php` 中开启 `method_vars` 以支持 PUT/DELETE
- **跨域**：API 项目需添加 CORS 中间件（参见 think-middleware）
- **认证**：推荐使用 JWT，避免 Session（无状态）
MARKDOWN,
        ];
    }

    /**
     * 扫描 {rootPath}/.ai/skills/ 目录加载自定义 skills
     *
     * 每个子目录里找 SKILL.md 文件。
     * 从 frontmatter 读 name 和 description（格式：---\nname: xxx\ndescription: xxx\n---）
     * 如果没有 frontmatter，用目录名作为 name。
     */
    private function loadCustomSkills(): void
    {
        $skillsDir = rtrim($this->rootPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . '.ai'
            . DIRECTORY_SEPARATOR . 'skills';

        if (!is_dir($skillsDir)) {
            return;
        }

        $entries = scandir($skillsDir);

        if ($entries === false) {
            return;
        }

        sort($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $skillsDir . DIRECTORY_SEPARATOR . $entry;

            if (!is_dir($entryPath)) {
                continue;
            }

            $skillFile = $entryPath . DIRECTORY_SEPARATOR . 'SKILL.md';

            if (!is_file($skillFile)) {
                continue;
            }

            $content = file_get_contents($skillFile);

            if ($content === false || $content === '') {
                continue;
            }

            $parsed = $this->parseFrontmatter($content);

            $skillName   = ($parsed !== null && $parsed['name'] !== '') ? $parsed['name'] : $entry;
            $description = ($parsed !== null) ? $parsed['description'] : '';
            $body        = ($parsed !== null) ? trim($parsed['body']) : trim($content);

            $this->custom[$skillName] = [
                'name'        => $skillName,
                'description' => $description,
                'content'     => $body,
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

    /**
     * 将 URI 转换为 skill name
     *
     * 'thinkphp://skills/think-model' -> 'think-model'
     * 其他格式返回 null
     */
    private function uriToName(string $uri): ?string
    {
        $prefix = 'thinkphp://skills/';

        if (!str_starts_with($uri, $prefix)) {
            return null;
        }

        $name = substr($uri, strlen($prefix));

        if ($name === '' || str_contains($name, '/')) {
            return null;
        }

        return $name;
    }
}
