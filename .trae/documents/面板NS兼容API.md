# 面板提供 Nightscout 兼容 READ API

## Context

面板（/workspace/panel）目前只有自己的会话型端点（/data/*.json），第三方工具、CGM 上传器和脚本只能绕过面板直接对接 Nightscout 后端。用户需求：**面板自身提供与 Nightscout v1 API 形状一致的只读端点**（读本地镜像），使任何会「说 Nightscout API」的客户端可以直接把面板当后端用。鉴权复用会话，或用户已持有的 Nightscout token（`api-secret` 头）——不引入新密钥。写路径保持直连 Nightscout 不变。

## 已验证事实（实现依据）

- **NS v1 形状**（/workspace/lib）：entries.json/treatments.json/devicestatus.json/profile.json 返回**裸数组、最新在前**的原始文档（含 `_id`）；status.json 是固定键对象。`Last-Modified` 头 + `If-Modified-Since` 更新时回 **304** `{status:304,message:'Not modified',type:'internal'}`。未授权回 **401** `{status:401,message:'Unauthorized',description:'Invalid/Missing'}`（lib/middleware/send-json-status.js）。
- **参数语义**：entries `count` 默认 10（lib/constants.json），treatments 默认 100（带 find[] 时 1000），devicestatus 默认 10（count=0 → 空数组）；find[] 支持 `find[type]`、`find[date][$gte]/[$lt]`（ms）、`find[device]`；scoped token 被服务端按设备过滤——面板镜像天然按 user_id 隔离，语义等价。
- **面板框架**：`config/route.php` `url_html_suffix=''` + `url_route_must=true`；`ext('json')` 钉后缀（data/glucose 先例）。分组中间件先于路由中间件执行，同组按声明顺序（`think/route/Rule.php` merge + 稳定排序）。全局 ViewGlobals 已填充 PanelContext，全局 StartSession 对 API 调用可用（session 分支零成本）。
- 镜像数据按 user_id（entries/treatments/devicestatus）与 backend_id（profiles）隔离；`panel_devicestatus` 每用户只存最新一条。

## 实施步骤

### 1. Mirror 新增读助手 — `app/library/Mirror.php`
- `apiEntries(int $userId, ?string $type, ?int $startMs, ?int $endMs, int $limit): array` — `$type` null 时 sgv+mbg 合并；内层 `ORDER BY ts DESC LIMIT n` → **最新在前**；返回解码后的 raw 文档（沿用 `rawEntries` 的子查询风格）。
- `apiTreatments(int $userId, ?string $startIso, ?string $endIso, int $limit): array` — `created_at` 窗口可空，`ORDER BY created_at DESC LIMIT n`。
- 不改动现有方法。

### 2. Throttle 增加 JSON 模式 — `app/middleware/Throttle.php`
`handle(..., string $bucket, int $limit, int $window, string $mode = 'page')`：`$mode === 'json'` 且超限直接回 `json(['status'=>429,'message'=>'Too many requests','description'=>'Rate limit exceeded'], 429)`；默认 `page` 保持 login/register 行为不变（仿 CsrfGuard 的 mode 参数）。

### 3. 新建 `app/middleware/ApiAuth.php`
1. 会话分支：`PanelContext::user() ?? Auth::user()` 非空 → setUser 放行。
2. `api-secret` 头（**仅头，不支持 `?token=` 查询串**，避免 token 入日志/Referer）：空 → 401。
3. Token 匹配循环：`SELECT id, username, role, backend_id, ns_access_token_enc FROM panel_users WHERE ns_access_token_enc IS NOT NULL`；逐行 `Db::decrypt`（RuntimeException → continue）+ `hash_equals($plain, $provided)`；匹配即 `PanelContext::setUser($row)` 并 early-exit。自建用户量极小，无 schema 改动、无 token 哈希列。
4. 全不匹配 → `json(['status'=>401,'message'=>'Unauthorized','description':'Invalid/Missing'], 401)`（精确复刻 NS 形状；中间件直接回 JSON，绕过 ExceptionHandle，CsrfGuard 先例）。

### 4. 新建 `app/controller/NsApiController.php`（继承 BaseController）
- 私有助手 `parseEntriesFind(Request $request)`：`$request->get('find')` 原生嵌套数组（config 无 default_filter）；**白名单** `type`（sgv|mbg）、`date`（`$gte`/`$lt` ms 整数）、`device`（字符串或 `$in` 数组，取回后 PHP 侧过滤，等价 NS scoped 交集）；**未知 find 键 → 400**（NS 形状错误体，fail-closed）。`count`：`max(0, min((int)count, 1000))`，count=0 → 空数组。
- 五个 action（均 `PanelContext::requireUser()` → Mirror）：
  - `entries`：`Mirror::apiEntries` → 数组；`Last-Modified`（首条 date ms → GMT 格式）；`If-Modified-Since` 秒级 `<=` 比较 → 304 `{status:304,message:'Not modified',type:'internal'}`。
  - `treatments`：`Mirror::apiTreatments`，同样 Last-Modified/304（首条 created_at）。
  - `devicestatus`：`[Mirror::latestDeviceStatus]` 或 `[]`（镜像只存最新一条）。
  - `profile`：`Mirror::profiles($user['backend_id'])` → 每条 `store` 投影仅保留 `$user['username']` 键（等价 lib/api/profile/index.js projectRecords）。
  - `status`：`{status:'ok', name:'NightPanel', version, serverTime, serverTimeEpoch, apiEnabled:true, careportalEnabled:false, boluscalcEnabled:false, settings:['units'=>'mg/dl'], extendedSettings:{}, authorized:[], runtimeState:'loaded'}`。
- 输出统一 `$this->jsonOut()`。

### 5. 路由 — `route/app.php`
```php
Route::group(function () {
    // 每条同时注册 ext('json') 与裸路径两种（NS 两种都提供）
    Route::get('api/v1/entries', 'NsApi/entries')->ext('json');
    Route::get('api/v1/entries', 'NsApi/entries');
    // ... treatments/devicestatus/profile/status 同式
    Route::get('api/v1/profile/current', 'NsApi/profile')->ext('json'); // 单对象别名（复用 action，输出 [0]）
})->middleware(Throttle::class, 'api', 120, 60, 'json')
  ->middleware(ApiAuth::class);
```
Throttle 声明在 ApiAuth 之前（先计数后鉴权，爆破请求超限得 429 而非 401）。

### 6. 文档
- `view/settings.html` API tab 新增「面板端 API」卡片：基址（ApiController 注入 `panelUrl`，取 `$request->domain()`）、同一 token、entries/treatments/profile/status 四条 curl 示例。
- `lang/zh_cn.php` + `en.php` 新增 `api.panel*` 键（lang-test 强制 parity + used-key-defined）。
- `panel/README.md` 新增「面板端 Nightscout 兼容 API」小节：端点表、find 参数子集、鉴权、限流（120/min）、未安装时 302 安装页的说明。

### 7. 测试
- `tests/http-test.php`：case 元组扩展可选 headers（env `PANEL_HTTP_HEADERS` JSON → `$_SERVER['HTTP_API_SECRET']`）；父进程夹具预置用户（`Db::encrypt('test-token')`）+ `Mirror::storeEntry/storeTreatment` + 含他人 store 的 profile payload。新 case：无头 401、错 token 401、有效 token 200 + newest-first + 字段形状、count 生效、If-Modified-Since 304、profile store 投影、`.json` 与裸路径双形态、未知 find 键 400。429 断言在父进程直接实例化 Throttle::handle（避免共享 IP 桶污染其他 case）。
- `tests/mirror-test.php`：apiEntries/apiTreatments 的窗口、LIMIT、newest-first、sgv+mbg 合并排序。
- `tests/views-xss-test.php`：settings api 夹具补 `panelUrl`。

## 验证

```
php panel/tests/mirror-test.php
php panel/tests/http-test.php
php panel/tests/lang-test.php && php panel/tests/views-xss-test.php
# 全量：for t in panel/tests/*.php; do php $t; done
# 手动（开发服务器 + 本地 Nightscout 装好后）：
#   curl -i 'http://127.0.0.1:8090/api/v1/entries.json?count=3' -H 'api-secret: <token>'   → 数组、最新在前
#   curl -i -H "If-Modified-Since: <Last-Modified 值>" ...                                  → 304
#   curl -i '.../api/v1/entries.json?find[foo]=1' -H ...                                    → 400
#   /api/v1/entries（无后缀）与 .json 双形态均 200；entries.xml → 404
```

## 不做的事（明确边界）

- 写路径（POST entries/treatments）不在本次范围——写仍直连 Nightscout。
- 不做 `find[device]` 之外的 Mongo 操作符全集（`$in`/`$eq` 之外的一律 400）。
- 不做 If-None-Match/ETag，只做 Last-Modified/If-Modified-Since（与 NS fork 行为一致）。
- 不做 CORS 头（浏览器第三方场景不存在；CSP 已限制本源）。
