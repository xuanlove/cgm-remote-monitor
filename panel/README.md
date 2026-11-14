# NightPanel — Nightscout 多用户 PHP 面板（ThinkPHP 8）

以 Nightscout 为纯后端的多用户面板。每个注册用户在 Nightscout 内获得一个**以用户名命名的 Profile 档案**（`store[<username>]`）与一个**对象级受限 token**，只能读写自己的档案并上传自己的数据，无法查看他人数据。

## 架构要点

- 单 Nightscout 实例承载多用户；隔离由三层实现：
  1. **Nightscout 权限改造**（本仓库内）：`api:profile:<name>:read/write` 作用域权限——token 直读 profile 只返回 `store[<name>]`，写入被服务端强制合并/剥离（见 `lib/api/profile/index.js`、`lib/authorization/index.js`）
  2. **面板归属过滤**：entries 按 `device=<username>`、treatments/activity 按 `enteredBy=<username>` 过滤，写入时强制注入归属
  3. **最小 token 权限**：用户 token 只有 profile 作用域读写 + 各集合 create（无任何 read）
- 面板发放：Nightscout 地址 + 用户 token + 面板网关说明（/myapi 页）

## 依赖

- PHP 8.1+（curl、openssl、pdo_sqlite、sqlite3）
- Composer 2
- 本仓库的 Nightscout 实例（先 `npm ci && npm run bundle` 构建）

## 目录结构

```
panel/
├── app/
│   ├── controller/     15 个控制器（Index/Auth/Lang/Dashboard/Data/Settings/
│   │                   Profile/Plan/Entry/Treatment/Report/Export/Api/Admin/Error）
│   ├── middleware/     SecurityHeaders|NormalizePath|StartSession|ViewGlobals|
│   │                   CsrfGuard|Throttle|GuestOnly|Authenticate|AdminOnly
│   ├── service/        PanelContext|UserService|UserProvisioner|ProfileService|
│   │                   TreatmentService|ReadingService|CsvService|
│   │                   SyncRunner|CleanupRunner|HealthRunner
│   ├── library/        NightPanel\*（Auth/Db/NsConfig/Mirror/Sync/NsClient/
│   │                   NsException/Alerts/GlucoseStats/TreatmentTypes/Lang）
│   ├── command/        sync / cleanup / health 控制台命令
│   ├── support/helpers.php   t() / t_html() / js_json() / panel_db_file()
│   ├── AppService.php  绑定异常处理器
│   └── middleware.php  全局中间件
├── config/             app|database|route|view|session|console|cache|log
├── route/app.php       全部公开 URL（与原面板逐条一致）
├── view/               ThinkPHP 模板（layout/base.html + 13 个页面）
├── public/             Web 根目录：index.php、router.php、.htaccess、static/
├── lang/               zh_cn.php、en.php（各 322 个 key）
├── bin/                sync.php / cleanup.php / health.php（cron 兼容薄壳）
├── tests/              6 个自检脚本
├── data/               SQLite（默认位置，必须在 public 之外）
├── runtime/            ThinkPHP 缓存/日志/编译模板（需可写）
└── vendor/             Composer 依赖（不提交）
```

### 数据访问约定

数据层仍是 `NightPanel\Db`（原生 PDO）与 `NightPanel\Mirror`：镜像表依赖 SQLite 特性（WAL、`ON CONFLICT` upsert、`BEGIN IMMEDIATE` 限流），改用 ORM 只会引入行为漂移。ThinkPHP 只承担 HTTP 内核、路由、中间件、模板与控制台。

## 运行模式：Web 只读本地镜像，计划任务拉取 Nightscout

```
浏览器 ──> nginx/Apache ──> php-fpm ──> SQLite 镜像（毫秒级，只读）
                              │
                              └─ 写入类操作 ──> Nightscout API（返回记录直接写回镜像）

cron/systemd timer ──> panel/bin/sync.php ──> Nightscout API
```

- Web 请求**不再直接访问 Nightscout**：`/data/*.json`、报告、导出、Profile/治疗列表全部读 `panel_entries` / `panel_treatments` / `panel_devicestatus` / `panel_profiles`。
- `bin/sync.php`（或 `php think sync`）按用户增量拉取：每条流在 `panel_sync_state` 里保存毫秒游标，并回看 6 小时重叠窗口（CGM 补传不会漏），按 Nightscout `_id` 幂等 upsert。
- 实时性由 30 秒轮询替代 SSE，不再长期占用 php-fpm worker。
- 写入（填写数据 / 治疗编辑）成功后，用 Nightscout 返回的记录直接更新本地镜像；Profile / 治疗方案的写路径**实时读取** Nightscout 当前文档（避免用陈旧镜像覆盖他人档案），成功后刷新一次 Profile 快照。
- 数据新鲜度：`/data/glucose.json` 返回 `sync.age_sec` 并带 `X-Data-Age` 头；仪表盘超过 10 分钟显示「数据延迟」。

## 安装

```bash
cd panel
composer install --no-dev --optimize-autoloader   # 生成 vendor/ 与 vendor/services.php
mkdir -p runtime data && chmod 0775 runtime data  # 属主与 php-fpm / cron 相同
php think clear                                    # 清模板缓存（首次可跳过）
```

### 生产部署（php-fpm + nginx）

站点根目录必须是 **`panel/public`**（app/、config/、runtime/、vendor/ 都在其外，天然不可访问）。

```nginx
server {
    listen 80;
    server_name panel.example.com;
    root /var/www/cgm-remote-monitor/panel/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }
    location ~ \.php(/|$) {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }
    location ~* \.(?:js|css|png|svg|woff2?)$ { expires 7d; access_log off; }
}
```

Apache：`DocumentRoot` 指向 `panel/public`，`public/.htaccess` 已含前端控制器重写与 `Options -Indexes`。

php-fpm 会清空环境变量；需要时在 pool 里显式传入（都是**可选**的覆盖项）：

```ini
; /etc/php/8.1/fpm/pool.d/panel.conf
env[NIGHTSCOUT_URL] = http://127.0.0.1:1337
env[NIGHTSCOUT_ADMIN_SECRET] = <API_SECRET>
env[PANEL_SECRET_KEY] = <32 位随机串>
env[PANEL_DB] = /var/lib/nightpanel/panel.db
env[PANEL_TRUST_PROXY] = 1
```

`panel/data/`（或 `PANEL_DB` 所在目录）需允许 php-fpm 与 cron 的同一用户读写，且**不能位于 Web 根目录内**：数据库、`<db>.key`（密钥种子）与 `wal/shm` 文件都在那里。启用 OPcache。

### 计划任务（路径与参数均未变化）

```cron
# 每分钟：增量同步所有用户
* * * * *   php /var/www/cgm-remote-monitor/panel/bin/sync.php >> /var/log/nightpanel-sync.log 2>&1

# 每 15 分钟：刷新 Profile 快照（全局共享一次请求）
*/15 * * * * php /var/www/cgm-remote-monitor/panel/bin/sync.php --profiles >> /var/log/nightpanel-sync.log 2>&1

# 每天 03:05：30 天全量校准（修复游标漂移 / 补历史）
5 3 * * *   php /var/www/cgm-remote-monitor/panel/bin/sync.php --full --days=30 >> /var/log/nightpanel-sync.log 2>&1

# 每天 03:30：保留策略清理（血糖 120 天、治疗 365 天、日志 7 天、告警事件 30 天）
30 3 * * *  php /var/www/cgm-remote-monitor/panel/bin/cleanup.php >> /var/log/nightpanel-cleanup.log 2>&1
```

`bin/*.php` 是薄壳，等价的控制台命令（便于手工排查）：

```bash
php panel/think sync --user=<name> --days=2
php panel/think sync --profiles
php panel/think sync --full --days=30
php panel/think cleanup --vacuum
php panel/think health --stale=600      # 退出码 1 表示有用户数据过期/从未同步
```

systemd timer（推荐，日志交给 journald）：

```ini
# /etc/systemd/system/nightpanel-sync.service
[Service]
Type=oneshot
User=www-data
EnvironmentFile=/etc/nightpanel/env
ExecStart=/usr/bin/php /var/www/cgm-remote-monitor/panel/bin/sync.php

# /etc/systemd/system/nightpanel-sync.timer
[Timer]
OnBootSec=30s
OnUnitActiveSec=60s
[Install]
WantedBy=timers.target
```

### 本地开发

```bash
# 0. Nightscout —— AUTH_DEFAULT_ROLES=denied 是安全前提（否则默认 readable 角色会泄漏读权限）
CUSTOMCONNSTR_mongo="mongodb://127.0.0.1:27017/nsdb" \
API_SECRET="<你的密钥>" \
AUTH_DEFAULT_ROLES="denied" \
INSECURE_USE_HTTP=true PORT=1337 node lib/server/server.js

# 1. 面板
cd panel
composer install
PANEL_ADMIN_USER=admin PANEL_ADMIN_PASS=<初始密码> \
NIGHTSCOUT_URL=http://127.0.0.1:1337 \
NIGHTSCOUT_ADMIN_SECRET=<与上面相同的API_SECRET> \
php -S 0.0.0.0:8090 -t public public/router.php

# 另开一个终端跑同步（本地没有 cron 时手动触发）
php bin/sync.php
php bin/sync.php --profiles
```

首次访问会自动创建 SQLite（`panel/data/panel.db`）与初始 admin（凭据打印在 error_log）。内置服务器只用于开发；生产必须用 php-fpm。

## Nightscout 连接配置

配置**以数据库为准**，在 `/admin/users` 页面维护：

| 键 | 说明 |
|---|---|
| `ns_url` | Nightscout 地址（明文，仅允许 http/https） |
| `ns_secret` | API_SECRET，AES-256-GCM 加密存储（`enc:v1:` 前缀） |
| `ns_config_updated_at` | 最后修改时间 |

- 表单留空 = 保持不变，不会清空已有配置；保存后**下一个请求立即生效**（`NsConfig::client()` 不做静态缓存）。
- 环境变量 `NIGHTSCOUT_URL` / `NIGHTSCOUT_ADMIN_SECRET` 若存在则**优先于数据库**；页面底部会显示 URL 与密钥各自的来源（环境变量 / 数据库 / 未配置），避免「改了没生效」的困惑。
- 环境变量不会自动回写数据库：轮换密钥应当由运维显式操作。

## 环境变量

| 变量 | 说明 |
|---|---|
| `NIGHTSCOUT_URL` | Nightscout 地址（覆盖数据库） |
| `NIGHTSCOUT_ADMIN_SECRET` | Nightscout API_SECRET 原文（覆盖数据库） |
| `PANEL_ADMIN_USER` / `PANEL_ADMIN_PASS` | 初始管理员（仅首次建库时生效） |
| `PANEL_SECRET_KEY` | token/密钥加密密钥。未设置时自动生成并写入 `<PANEL_DB>.key`（权限 0600），**绝不落在数据库内**——否则一份 `panel.db` 同时带走密文与密钥。旧版 `panel_settings.key_seed` 会自动迁移到该文件，旧密文仍可解密 |
| `PANEL_DB` | SQLite 路径（默认 `panel/data/panel.db`；生产建议放到独立可写目录） |
| `PANEL_TRUST_PROXY` | 设为 `1` 时信任 `X-Forwarded-Proto`（反向代理终止 TLS 时用于 `Secure` cookie）。未设置时仅当对端是回环/内网地址才信任 |

## 功能页

- `/register` 注册 → 自动创建 `store[<username>]` + 专属角色（`api:profile:<username>:read/write/create` + 各集合 create）+ subject，页面上直接展示发放的地址与 token
- `/dashboard` 血糖趋势（ECharts，mmol/L）：趋势箭头 + Δ 变化 + IOB/COB/电量、校准血糖（mbg）叠加、任意历史日回看、3 点平滑；每 30 秒轮询本地镜像，数据延迟超过 10 分钟显示提示，越线时显示告警横幅
- `/reports` 统计报告（近 7/14/30/90 天）：TIR/TAR/TBR、血糖分布（5 档）、平均值/中位数/标准差/CV、GMI、低血糖事件（≥15 分钟）、数据覆盖率，以及落在本人 Profile 目标范围的占比
- `/export/entries.csv|json`、`/export/treatments.csv` 数据导出（`?days=` 夹在 1..90，CSV 带 UTF-8 BOM）
- `/settings/alerts` 个人提醒线：低/高阈值（存面板数据库），可同时开启浏览器桌面通知；阈值由同步任务在服务端评估，事件写入 `panel_alert_events`，页面轮询后通知并回执
- `/profile` 编辑当前方案的配置档（基础率/敏感系数/目标范围/DIA/碳水速率）——以用户 token 直连提交，Nightscout 服务端强制作用域
- `/profile/plans` 治疗方案版本：按 startDate 倒序列出，当前生效 / 历史；「启用此方案」以该版本内容写入一条新记录；共用记录与他人档案不可删除
- `/entry` 填写数据：手填血糖 + 全部治疗事件类型（20 种，表单字段由服务端注册表驱动）+ 活动记录
- `/treatments` 我的记录：列出自己的治疗记录，支持编辑与删除（归属 `enteredBy` 由服务端校验）
- `/myapi` 专属地址、token（掩码）、curl 示例、CGM 上传器配置指引
- `/admin/users` 用户管理、Nightscout 连接状态与配置来源、删除用户（联动删除 subject / 角色 / 镜像数据）

### 用户 token 的权限

```
api:profile:<username>:read     读自己的 Profile 档案（他人 store 键被服务端剥离）
api:profile:<username>:write    更新自己的档案
api:profile:<username>:create   新建只含自己档案的治疗方案版本
api:entries:<username>:read     读自己设备上传的血糖（api v1 entries）
api:entries/treatments/activity/devicestatus:create   上传数据
```

作用域读只在 v1 entries 生效：`/api/v2/properties`、api3、data 端点仍要求完整的 `api:entries:read`。

### 治疗事件类型

事件类型与字段的唯一定义处是 `app/library/TreatmentTypes.php`：表单由此生成，控制器也据此校验。新增类型只需在 `types()` 里加一行并复用 `fields()` 中的字段。

### 多语言

- 界面支持 **简体中文（默认）与 English**，导航右上角的语言按钮即时切换
- 选择顺序：会话 → cookie → `Accept-Language` → 默认 `zh_cn`
- 文案集中在 `panel/lang/zh_cn.php` 与 `panel/lang/en.php`（扁平 key → 文本，支持 `:name` 占位符）；控制器统一 `t('key')`，模板里无参数文案用 `{:t('key')}`、**带变量文案一律用 `{:t_html('key', [...])}`**（函数输出不经过模板默认转义）
- 新增文案只需在两个字典里各加一行；`php panel/tests/lang-test.php` 会校验两份字典 key 与占位符完全一致，并扫描 `app/` 与 `view/` 中用到的 key 是否都已定义

## 安全基线

- 所有响应带 `X-Content-Type-Options`、`X-Frame-Options: DENY`、`Referrer-Policy: same-origin` 与限制为本源的 CSP。
- 模板默认 `htmlentities` 转义；`|raw` 只允许控制器注入的 JSON 变量（白名单由 `views-xss-test.php` 强制）；动态列表用 `textContent` 构建。
- `ns_secret` 加密落库，密钥种子存放在数据库之外（`<db>.key`，0600）；`public/` 之下的 Web 根不包含任何源码或数据目录。
- 登录按客户端地址 20 次/15 分钟、注册 10 次/小时限流（`panel_rate_limits`，IP 以 SHA-256 存储）。
- CSRF：表单字段 `csrf` 或 `X-CSRF-Token` 头；退出登录是 POST + CSRF（`GET /logout` 返回 405）。
- 导出强制 `Content-Disposition: attachment`；`/data/*.json` 仅登录用户可读。

## 测试

Nightscout 权限改造的单元测试：`tests/profile-scoped.test.js`

```bash
npx env-cmd -f ./tests/ci.test.env npx mocha --timeout 30000 --require ./tests/hooks.js --exit ./tests/profile-scoped.test.js ./tests/entries-scoped.test.js
```

面板自检（无需 Nightscout、无需 Web 服务器）：

```bash
php panel/tests/glucose-stats-test.php   # 统计计算
php panel/tests/alerts-test.php          # 提醒阈值规则
php panel/tests/lang-test.php            # 双语字典与代码用词
php panel/tests/mirror-test.php          # 镜像表、隔离、游标、加密、限流
php panel/tests/views-xss-test.php       # 模板转义与 |raw 白名单
php panel/tests/http-test.php            # 路由矩阵（每个用例独立进程）
```

## 升级与回滚

- 升级：备份 `panel.db`、`<db>.key`（以及 `-wal/-shm`）→ 拉代码 → `composer install --no-dev -o` → `php think clear` → 重启 php-fpm。
- 数据库 schema 未变（`PRAGMA user_version` 仍为 1），旧库可直接复用；密钥派生已改为不含文件路径，并保留对旧路径盐的解密回退，因此旧 token / `ns_secret` 在升级后仍可解出。
- 回滚：把代码切回上一个 release，确认 `PANEL_DB` 指向同一文件即可；若曾改动 nginx，请把 `root` 一并回退。
