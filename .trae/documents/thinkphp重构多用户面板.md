# ThinkPHP 8 重构多用户面板（panel/）

## Context

现有面板是一个单文件前端控制器：`panel/index.php`（1080 行 `switch`、32 个分支）+ `panel/lib/` 10 个类 + `panel/views/` 11 个原生 PHP 视图 + `panel/bin/` 3 个 CLI + 5 个自检脚本。两轮安全审计的修复全部落在这套结构里。

目标：用 **ThinkPHP 8** 完全重构 HTTP 层（路由、控制器、中间件、模板、控制台命令），并把 **Nightscout 配置（地址 + API_SECRET）持久化到数据库**，由后台页面维护。**行为与 URL 必须与现在等价**，线上 SQLite 数据与 cron 不动。

已确认的决策：
- ThinkPHP 8.x（线上 PHP 8.1+）
- 视图改用 ThinkPHP 模板引擎
- 站点根目录改为 `panel/public`
- 原地重构 `panel/`，保留现有数据库

## 非目标（明确不做）

- **不改 SQLite 表结构、不改 `PRAGMA user_version`（保持 1）**。线上库要能直接复用、可回滚。
- **不用 TP 的 Db/Model 重写 SQL**。限流依赖 `BEGIN IMMEDIATE`、镜像表用 `ON CONFLICT` UPSERT 与 JSON 文本列、`PRAGMA` 是 SQLite 专有行为，ORM 只会引入行为漂移。数据层继续用 `NightPanel\Db` / `Mirror` 的 PDO 实现。
- **不改 URL 形态**：不加 `.html`、不出现 `/index.php`、保留 `.json/.csv` 后缀、不改大小写。
- **不用 TP 的 Session 门面 / SessionInit**：cookie 名、HttpOnly、SameSite、Secure 的代理感知、CSRF 行为必须逐字节保持。
- **不把 i18n 换成 TP `lang()` 机制**：322 key 对等测试与 `Lang::js()` 的 JS 注入都依赖现有扁平字典。
- 不引入 Redis/队列/前端构建链，不改内嵌图表的 JS 逻辑。

## 目标结构

```
panel/
├── app/
│   ├── middleware.php  AppService.php  ExceptionHandle.php
│   ├── controller/     Index|Auth|Lang|Dashboard|Data|Settings|Profile|Plan|
│   │                   Entry|Treatment|Report|Export|Api|Admin|Error
│   ├── middleware/     SecurityHeaders|NormalizePath|StartSession|ViewGlobals|
│   │                   CsrfGuard|Throttle|GuestOnly|Authenticate|AdminOnly
│   ├── command/        SyncCommand|CleanupCommand|HealthCommand
│   ├── service/        PanelContext|UserService|UserProvisioner|ProfileService|
│   │                   TreatmentService|ReadingService|CsvService|
│   │                   SyncRunner|CleanupRunner|HealthRunner
│   ├── library/        Auth|Db|NsConfig|Mirror|Sync|NsClient|NsException|
│   │                   Alerts|GlucoseStats|TreatmentTypes|Lang
│   └── support/helpers.php      t() / t_html() / js_json() / panel_db_file()
├── config/             app|database|route|session|view|console|log.php
├── route/app.php
├── view/               layout/base.html + 13 个页面模板
├── public/             index.php  router.php  .htaccess
│                       static/vendor/{echarts.min.js,chart.umd.js}
├── lang/               原样（zh_cn.php、en.php）
├── bin/                sync.php cleanup.php health.php（薄壳，cron 不变）
├── tests/              5 个自检（改用 composer autoload）
├── data/               PANEL_DB 默认位置（public 之外）
├── runtime/            TP 缓存/日志/模板编译（需可写）
├── vendor/             仅 Composer
├── composer.json / composer.lock / think
```

映射：`index.php` → `route/app.php` + 控制器 + service；`lib/*` → `app/library/*`（命名空间仍是 `NightPanel`，PSR-4 映射到 `app/library/`）；`views/*.php` → `view/*.html`；`vendor/*.js` → `public/static/vendor/`；`router.php`、`.htaccess` → `public/`。

## 关键实现点

### 1. 三个必须先修的数据层问题（P0）

1. **密钥盐**：`Db::secretKey()` 现在把 `__FILE__` 参与哈希。文件从 `panel/lib/Db.php` 移到 `panel/app/library/Db.php` 后，所有已加密的 `ns_secret` 与用户 token 都会解不开。改为固定盐（`'night-panel|db-v1|' . keySeed()`），并保留**旧路径盐**回退：旧盐用 `dirname(__DIR__, 2) . '/lib/Db.php' . '|night-panel|' . seed` 计算（不需要旧文件存在），解密时先试新盐再试旧盐；新写入一律用新盐。
2. **默认库路径**：`Db::file()` 由 `__DIR__ . '/../data/panel.db'` 改为 `dirname(__DIR__, 2) . '/data/panel.db'`，否则会漂到 `app/data/`。生产仍建议显式 `PANEL_DB`。
3. **`NsException` 拆文件**：它现在和 `NsClient` 同文件，PSR-4 下自动加载不到，必须独立成 `app/library/NsException.php`。

### 2. 路由（`route/app.php`，32 条）

- `config/route.php`：`url_html_suffix=''`、`route_complete_match=true`、`url_route_must=true`、`url_convert=false`、`controller_suffix=true`（避免 `AuthController` 与 `NightPanel\Auth` 撞名）。
- 全局中间件：`Authenticate` 包住所有业务路由；`AdminOnly` 挂 `/admin/*`；`CsrfGuard` 只挂已匹配的 POST（不能全局挂，否则未知 POST 会从 404 变 403、GET `/logout` 被误伤）；`Throttle` 以参数形式挂在 `POST /login`（20/900s）与 `POST /register`（10/3600s）。
- `/logout` 用 `Route::any` + 控制器内判 `isMethod('post')`，非 POST 返回 405 + `Allow: POST`。
- 参数：一律 `$request->get()` / `$request->post()`，不用 `param()`（会合并来源造成参数污染）；`POST /data/alerts/ack` 用 `$request->getContent()` + `json_decode`。
- `data/glucose.json`、`export/*.csv|json` 依赖后缀，需实测 TP 的分发；若后缀干扰匹配，改用 `Route::get(...)->ext('json')` 并补验收。

### 3. 中间件

顺序：`SecurityHeaders`（在 `$next` 前写 CSP 等 5 个头，保证异常响应也带）→ `NormalizePath`（GET 尾斜杠 301、非 GET 308）→ `StartSession`（**原生 session**：`session_name('nightpanel')`、httponly、SameSite=Lax、`secure=Auth::isHttps()`，再 `Lang::init()`，仅 Web 请求执行 `Auth::bootstrapAdmin()`）→ `ViewGlobals`（注入 `user/csrf/htmlLang/panelI18n/panelLocale`）。

路由级：`GuestOnly`、`Authenticate`（未登录 302 `/login`，并写入 `PanelContext`）、`AdminOnly`（403 纯文本）、`CsrfGuard`（字段 `csrf` 或头 `X-CSRF-Token`，失败 403）、`Throttle`（超限返回 **200 + 页面提示**，与现状一致，不是 429）。

### 4. Nightscout 配置入库

- 复用 `panel_settings`（不新建表）：`ns_url`（明文）、`ns_secret`（`enc:v1:` + AES-256-GCM）、可选 `ns_config_updated_at`。
- `NightPanel\NsConfig`（原 `Config`）：`baseUrl()`/`adminSecret()` 先看 env、再读 DB；`client()` **删除 `static` 缓存**，每次新建，保证后台改完下一请求立即生效；新增 `source()` 供后台显示「env / 数据库」来源。
- 保存规则：URL 空 = 不修改，仅允许 http/https 且 `rtrim('/')`；secret 空 = 不修改，禁止控制字符；写库走 `Db::setSecret`。
- env 只作为覆盖与首次引导的**可选**来源，不自动回写 DB（避免轮换密钥不可控）。
- 后台 `/admin/users` 增加「配置来源」提示与校验错误提示。

### 5. 视图

- `config/view.php`：`view_suffix=html`、`default_filter=htmlentities`、`tpl_cache=true`、缓存目录 `runtime/view`。
- 布局：`{extend name="layout/base" /}` + `{block name="content"}`。
- i18n：无参数文案用 `{:t('key')}`；**带变量文案一律用 `t_html('key', [...])`**（函数输出不经过 `default_filter`，这是新的 XSS 面）。
- JS 数据：控制器先算好 `Lang::js([...])` / `js_json($arr)` 并 assign，模板用 `{$panelI18n|raw}`；`|raw` 白名单只允许这些注入变量，禁止 `{$user.*|raw}`、`{$error|raw}`。
- 内嵌大段 JS 用 `{literal}` 包住，动态常量放在 `{literal}` 之前的独立 `<script>`。
- CSRF：`<input type="hidden" name="csrf" value="{$csrf}">`。
- 静态资源路径改为 `/static/vendor/echarts.min.js`。

### 6. CLI

- 注册 `php think sync|cleanup|health`（`config/console.php`），逻辑抽到 `SyncRunner/CleanupRunner/HealthRunner`，保留 flock、`<db>.sync.lock`、退出码（sync：有错 1、并发 2；health：过期 1）。
- `panel/bin/*.php` 保留为**薄壳**（只 `require vendor/autoload.php` + 调 Runner），现有 cron 一行都不用改。

### 7. 测试

- 5 个自检改为 `require vendor/autoload.php`；`glucose-stats/alerts/lang/mirror` 不需要框架 bootstrap，`mirror-test` 继续用临时 `PANEL_DB`。
- `views-xss-test` 改为用 `View::fetch()` 真实渲染（关闭模板缓存或用临时 `cache_path`），静态扫描目标换成 `view/**/*.html`：禁止裸 `json_encode(`、禁止 `row.innerHTML = '`、`|raw` 必须在白名单内、动态文案不得用 `{:t()}`。
- `lang-test` 扫描范围扩到 `app/{controller,service,library,middleware}` + `view/`。

## 实施阶段（每阶段结束都要能跑）

**阶段 0 · 准备**
- 备份生产 `panel.db`、`<db>.key`、nginx/php-fpm 配置；记录 `PRAGMA user_version=1`。
- `git mv panel/vendor/{echarts.min.js,chart.umd.js} panel/public/static/vendor/`；`panel/vendor` 从此只归 Composer；`.gitignore` 加 `/vendor/`、`/runtime/`。
- 写 `panel/composer.json`（`php>=8.1`、`topthink/framework ^8.0`、`topthink/think-view ^2.0`、`topthink/think-orm ^3.0`、`config.platform.php=8.1.0`、PSR-4 双映射、`files: app/support/helpers.php`），`composer install` 并提交 lock。

**阶段 1 · 搬类不改行为**
- `lib/*` → `app/library/*` 并修 3 个 P0 项；`lib/NsClient.php` 里的 `NsException` 拆出。
- 5 个自检全部通过（此时尚未接框架）。

**阶段 2 · 框架骨架**
- `public/index.php`、`think`、`config/*`、`app/middleware.php`、异常处理、9 个中间件、`PanelContext`。
- 32 条路由一次性接完，控制器按现有分支逐条搬迁（`Index/Auth/Lang/Dashboard/Data/Settings/Profile/Plan/Entry/Treatment/Report/Export/Api/Admin/Error`）。
- 验收路由矩阵（200/302/403/405/404）。

**阶段 3 · 视图迁移**
- 11 个视图 → 模板，逐页对照渲染；ECharts/轮询 JS 原样保留。
- 用恶意 fixture 验证默认转义与 `|raw` 白名单。

**阶段 4 · CLI + 配置入库**
- 3 个 Runner + 3 个 command + `bin/*` 薄壳；`NsConfig` 去静态缓存 + 后台来源提示。

**阶段 5 · 文档与部署**
- 重写 `panel/README.md`：目录结构、`composer install --no-dev -o`、nginx `root .../panel/public`、`runtime/` 权限、cron 不变、`php think` 命令、回滚步骤。
- 线上切换：备份 → 拉代码 → `composer install` → 改 nginx root → `php think clear` → reload → 冒烟。

## 验证

**自动化**
- `php panel/tests/{glucose-stats,alerts,lang,mirror,views-xss}-test.php` 全绿。
- 新增路由矩阵脚本：未登录业务页 302、登录 200、普通用户 `/admin/users` 403、未知 404、`GET /logout` 405 + `Allow`。
- CSRF：每个 POST 无 token 403；`/data/alerts/ack` 仅 `X-CSRF-Token` 通过。
- 限流：同 IP 第 21 次登录 / 第 11 次注册出现提示；不同 IP 互不影响。
- Cookie：`nightpanel` HttpOnly + SameSite=Lax；`X-Forwarded-Proto: https` + `PANEL_TRUST_PROXY=1` 时带 Secure。
- 响应头：CSP / X-Frame-Options / nosniff / Referrer-Policy 原文一致。
- 模板转义：11 页 + 404/error 渲染恶意 fixture，原串不出现。
- 导出：`Content-Disposition: attachment`、CSV BOM、`?days=` 夹到 1..90。
- 加密：`panel_settings.ns_secret` 无明文且 `enc:v1:` 前缀；`<db>.key` 0600；**旧密文可解**（用生产库副本验证）。
- 配置热更新：后台保存 `ns_url` 后下一请求 `NsConfig::baseUrl()` 立即变化。
- URL 审计：渲染结果里没有 `.html` 后缀、没有 `/index.php`，表单 action 与现网一致。

**手工**
- 用真实 Nightscout + 现有 `panel.db` 登录，逐页点一遍：仪表盘出图、报告数字、填写数据、治疗编辑/删除、方案启用/删除、导出、提醒保存、`/myapi`、管理员页。
- `php panel/bin/sync.php --user=<name> --days=2` 与 `php panel/think sync --user=<name>` 结果一致。

## 风险与规避

| 级别 | 风险 | 规避 |
|---|---|---|
| P0 | 密钥盐随文件路径变化 → 旧 token/`ns_secret` 全失效 | 固定盐 + 旧路径盐回退解密；用生产库副本先验证 |
| P0 | `Db::file()` 相对路径漂移 → 「数据库丢了」 | 改为 `dirname(__DIR__,2)`；生产显式 `PANEL_DB` |
| P0 | `NsException` 无法自动加载 | 拆文件 |
| P1 | 模板 `|raw` 滥用或 `{:t($var)}` 未转义 → XSS | `default_filter=htmlentities`；`t_html()`；`|raw` 白名单 + 静态扫描 |
| P1 | `url_html_suffix` 生成 `.html`，前端 fetch 全挂 | 设为空 + 硬编码绝对路径 + 渲染结果审计 |
| P1 | Session 参数丢失（Secure/HttpOnly/SameSite） | 不用 TP SessionInit，原生 session + `Auth::isHttps()` |
| P1 | CSRF 挂全局导致 404 变 403、`/logout` 被拦 | 只挂已匹配 POST；`/logout` 用 any + 控制器判断 |
| P1 | `panel/vendor` 与 Composer 冲突 | 先迁 JS 到 `public/static/vendor`；vendor 只归 Composer |
| P2 | `runtime/` 不可写 → 500 | 部署建目录、统一属主、`php think clear` |
| P2 | 方法不匹配由 404 变 405 | 视为合理改进，验收记录；`ExceptionHandle` 统一渲染错误页 |
| P2 | 沙箱 PHP 8.5 与线上 8.1 依赖解析差异 | `config.platform.php=8.1.0`；`composer install --no-dev` |
| P2 | 异常页泄露堆栈/绕过 CSP | `app_debug=false`；`SecurityHeaders` 前置；自定义 `ExceptionHandle` |

## 交付物

- 重构后的 `panel/`（TP8 骨架 + 控制器/中间件/模板/命令）
- `composer.json` + `composer.lock`
- 更新后的 `panel/README.md`（部署、cron、回滚）
- 5 个自检 + 新增路由/CSRF/限流/转义验收脚本

## 备注（不属于本次范围）

- 空库导致的 Nightscout 授权重建（`reprovision`）是独立任务；本次重构不改变它，可后续以 `php think reprovision` 形式补上。
- 宝塔当前 nginx root 指向 `panel/`，切换后必须改为 `panel/public`，否则 `/login` 等路由仍然 404。
