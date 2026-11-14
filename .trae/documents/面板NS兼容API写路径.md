# 面板兼容 API 增加写路径（读写全对齐）

## Context

面板已提供 Nightscout 形状的只读兼容 API（读镜像）。用户需求：**补齐写路径**——任何 Nightscout API 客户端（CGM 上传器如 xDrip+/Loop/AAPS、回填脚本）可以把面板当完整后端用：POST entries/treatments/devicestatus + PUT profile。写路径的设计原则：**代理转发到 Nightscout（真相源）+ 服务端强制归属（device=enteredBy=用户名）+ 回写本地镜像**——与面板自身写路径（TreatmentService::submit）同构，绝不只写镜像造成与后端分叉。

## 已验证事实

- **fork 写端点**（/workspace/lib）：POST 收单对象或数组（`lib/api/shared/write-payload.js` normalize，单条上限 **10000**，超限 `{status:400,message:'Too many entries'}`，形状错误 `{status:400,message:'Invalid entries payload'}`）；POST entries 响应**恒为数组**（`format_post_response` res.json(res.entries || [])）；POST treatments 缺 `created_at` 时服务端补当前时间；PUT profile 对 scoped token 走 `mergeScopedProfile`（把 scoped store 条目合并进现行记录，他人 store 键保留）——面板代理时带 **USER token** 即天然继承该语义。
- **面板侧**：`think\Request::getContent()` 读 php://input（CLI 子进程下 stdin 管道可注入，测试 harness 用 `printf %s body | php child`）；`NsClient::requestOrThrow` 支持 asUserToken（profile PUT 用）；`TreatmentService::created()` 是归一化单条/数组响应的现成模式；`Mirror::storeEntry` 硬编码 type='sgv' 不能复用，`panel_devicestatus` 无写方法，需新增两个通用写入助手。
- 只读路由组（Throttle→ApiAuth）已就位；PanelContext 为请求域静态（新写集成测试须逐请求重置）。

## 实施步骤

### 1. Mirror 写助手 — `app/library/Mirror.php`
- `storeApiEntry(int $userId, array $record): void` — 泛化版：`type` 取自记录（缺省 'sgv'），ts 解析 `date`/`mills`/`dateString`（复用 Sync::entryTs 的优先级逻辑，本地实现），值取 `sgv ?? mbg ?? null`，direction/delta/device 从记录；`ON CONFLICT(user_id, ns_id)` upsert。现有 `storeEntry`（手填路径）不动。
- `storeDeviceStatus(int $userId, array $record): void` — `panel_devicestatus` 每用户一行 upsert（PK user_id），payload 存原始 JSON。

### 2. ApiAuth 增加 token-only 模式 — `app/middleware/ApiAuth.php`
`handle(Request, Closure, string $mode = 'any')`：`$mode === 'token'` 时跳过会话分支，只认 `api-secret` 头——写路径语义与 NS 完全一致（写必须 api-secret），且避免会话身份与 token 身份的歧义。

### 3. NsApiController 写 action
私有助手：
- `documentsFromBody(Request $request, int $max = 10000): array{0:?array<int,array>,1:?Response}` — `json_decode(getContent())`，单对象 → `[doc]`，数组 → 原样；每个元素须为对象；非法 JSON/形状 → 400 NS 形状；超限 → 400 `'Too many entries'`（复刻 fork 文案）。
- `normalizeCreated(mixed $body): array` — NS 响应归一化为数组（关联数组 → `[body]`，列表 → 原样）。

四个 action（均 `ApiAuth token` 已注入用户）：
- `postEntries(Request)`：逐条**强制 `device = username`**；缺 `date` 但 `dateString` 可解析 → 补 date；单对象且 date/dateString 全缺 → 按 fork 历史语义回 `[]` no-op；数组 → 经 `Backends::clientFor($user)` 批量转发（`bulkPostEntries`），响应（恒数组）原样 `jsonOut`，逐条 `Mirror::storeApiEntry`。
- `postTreatments(Request)`：逐条**强制 `enteredBy = username`**，缺 `created_at` → `gmdate('c')`；批量 `bulkPostTreatments`；响应 jsonOut 透传 + 逐条 `Mirror::storeTreatment`。
- `postDeviceStatus(Request)`：逐条**强制 `device = username`**；批量 `bulkPostDeviceStatus`；响应 jsonOut 透传 + 逐条 `Mirror::storeDeviceStatus`。
- `putProfile(Request)`：防御性投影 body `store` 仅保留 `$user['username']` 键（缺自己的键 → 400）；body 缺 `_id` 时注入 `ProfileService::recordLive($user)` 的当前 `_id`；以 **USER token**（`Auth::userToken`）`requestOrThrow('PUT', '/api/v1/profile.json', …)` 转发（fork mergeScopedProfile 合并他人键）；响应透传 + `ProfileService::refreshSnapshot($user)`。
- 任一 NS 转发失败：`NsException` 冒泡 → ExceptionHandle 502（既有映射， Nightscout 不可用时上传器得明确错误）。

### 4. 路由 — `route/app.php`
```php
Route::group(function () {
    // 每条 POST/PUT 同时注册 ext('json') 与裸路径（与读路径一致）
    Route::post('api/v1/entries', 'NsApi/postEntries')->ext('json');
    ...
    Route::put('api/v1/profile', 'NsApi/putProfile')->ext('json');
    ...
})->middleware(Throttle::class, 'api', 120, 60, 'json')
  ->middleware(ApiAuth::class, 'token');
```

### 5. 测试
- **`tests/stub-nightscout.php`**（新）：php -S 路由脚本模拟 Nightscout 写端点——POST/PUT 收到 body 后赋 `_id`（stub-e/t/d-<n>）、**把最后收到的 body 写入日志文件**（供断言归属强制）、返回创建数组/对象。
- **`tests/api-write-test.php`**（新，集成）：父进程起 `php -S 127.0.0.1:<pid 派生端口> stub` + 建临时库（backend URL 指向 stub + apitester 用户 + token）；单进程内 boot 真 App 连续发请求（**每请求前 `PanelContext::setUser(null)`**）。断言：无 token 401；POST entries 单对象/数组 → 200 数组响应 + stub 收到的 body `device` 已被强制为用户名 + 镜像行落库（type/dateString 解析正确）；缺 date 单对象 → 200 `[]` no-op；POST treatments → `enteredBy` 强制 + `created_at` 补齐 + 镜像落库；POST devicestatus → 镜像 upsert；PUT profile → stub 收到的 store 只含自己的键 + 响应透传；非法 JSON/数组混入标量 → 400 NS 文案；清理 stub 进程（kill 记录的 PID）。
- **`tests/http-test.php`**：4 个不触 NS 的用例——写端点无 token 401、错 token 401、非法 JSON body 400（stdin 管道注入 `printf %s body |`）、非对象 payload 400。harness 扩展：case options 支持 `body`（stdin 管道）与 `method PUT`。
- **`tests/mirror-test.php`**：storeApiEntry（type 推导、dateString→ts、upsert 幂等）、storeDeviceStatus（覆盖更新）。

### 6. 文档
- `lang/zh_cn.php` + `en.php`：`api.panelIntro` 更新（写路径已支持 + 仅限 api-secret）、新增 `api.panelWriteExample` 键 + POST treatments curl 示例标题；`settings.html` 面板卡补一条 POST 示例。
- `panel/README.md`：兼容 API 章节表补 POST/PUT 行（归属强制、批量上限 10000、写仅限 api-secret、profile PUT 合并语义、DELETE 不在范围内）、CGM 上传器可直接把面板地址当 Nightscout 用（上传 URL = 面板 + /api/v1）。

## 验证

```
php panel/tests/mirror-test.php
php panel/tests/api-write-test.php      # 新集成测试（stub NS）
php panel/tests/http-test.php
php panel/tests/lang-test.php && php panel/tests/views-xss-test.php
# 全量 for t in panel/tests/*.php; do php $t; done → 10 个文件全绿
# 冒烟：stub 模式手工 curl POST entries → 数组响应 + 镜像可见
```

## 不做的事（边界）

- DELETE 路由（entries/:id 等）不在本次范围——上传器场景不涉及，README 注明。
- 读路径无任何改动；面板自身的表单写路径（/entry 等）不变。
