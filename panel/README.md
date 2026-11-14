# NightPanel — Nightscout 多用户 PHP 面板（ThinkPHP 8）

以 Nightscout 为纯后端的多用户面板，支持**多个 Nightscout 后端**。每个注册用户在所属后端内获得一个**以用户名命名的 Profile 档案**（`store[<username>]`）与一个**对象级受限 token**，只能读写自己的档案并上传自己的数据，无法查看他人数据。

## 架构要点

- **多后端注册表**：`panel_ns_backends`（友好名称 + 地址 + AES-256-GCM 加密的管理密钥）。注册时用户选择后端（页面显示友好名称），此后该账号所有数据访问都走其所属后端。
- 隔离由三层实现：
  1. **Nightscout 权限改造**（本仓库内）：`api:profile:<name>:read/write` 作用域权限——token 直读 profile 只返回 `store[<name>]`，写入被服务端强制合并/剥离（见 `lib/api/profile/index.js`、`lib/authorization/index.js`）
  2. **面板归属过滤**：entries 按 `device=<username>`、treatments/activity 按 `enteredBy=<username>` 过滤，写入时强制注入归属
  3. **最小 token 权限**：用户 token 只有 profile 作用域读写 + 各集合 create（无任何 read）
- **用户名唯一以面板为准**：跨后端全局唯一；一键迁移按账号幂等——目标端重建身份后全量重传数据，本地镜像清空由下次同步重灌，绝不双计数。
- **零环境变量配置**：所有参数（加密密钥种子、默认语言、信任代理、后端表）由 `public/install.php` 安装向导一次性写入 SQLite，`data/install.lock` 落锁后安装器自动关闭。
- 面板发放：后端友好名称 + 地址 + 用户 token + 面板网关说明（设置 → 我的 API）

## 依赖

- PHP 8.1+（curl、openssl、pdo_sqlite、sqlite3）
- Composer 2
- 至少一个本仓库的 Nightscout 实例（先 `npm ci && npm run bundle` 构建）

## 目录结构

```
panel/
├── app/
│   ├── controller/     15 个控制器（Index/Auth/Lang/Dashboard/Data/Settings/
│   │                   Profile/Plan/Entry/Treatment/Report/Export/Api/Admin/Error）
│   ├── middleware/     EnsureInstalled|SecurityHeaders|NormalizePath|StartSession|
│   │                   ViewGlobals|CsrfGuard|Throttle|GuestOnly|Authenticate|AdminOnly
│   ├── service/        PanelContext|UserService|UserProvisioner|ProfileService|
│   │                   TreatmentService|ReadingService|CsvService|BackendMigration|
│   │                   SyncRunner|CleanupRunner|HealthRunner
│   ├── library/        NightPanel\*（Auth/Db/Backends/Mirror/Sync/NsClient/
│   │                   NsException/Alerts/GlucoseStats/TreatmentTypes/Lang）
│   ├── command/        sync / cleanup / health 控制台命令
│   ├── support/helpers.php   t() / t_html() / js_json() / panel_db_file() / panel_lock_file()
│   ├── AppService.php  绑定异常处理器
│   └── middleware.php  全局中间件
├── config/             app|database|route|view|session|console|cache|log
├── route/app.php       全部公开 URL
├── view/               ThinkPHP 模板（layout/base.html + 12 个页面，
│                       Profile/方案/提醒/我的API 合并为 settings.html 四标签）
├── public/             Web 根目录：index.php、install.php、router.php、.htaccess、static/
├── lang/               zh_cn.php、en.php
├── bin/                sync.php / cleanup.php / health.php（cron 兼容薄壳）
├── tests/              9 个自检脚本
├── data/               SQLite + install.lock（默认位置，必须在 public 之外）
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

面板**不使用任何环境变量**：管理员账号、加密密钥、站点默认语言、信任代理开关与第一个 Nightscout 后端，全部由安装向导写入数据库。

```bash
cd panel
composer install --no-dev --optimize-autoloader   # 生成 vendor/ 与 vendor/services.php
mkdir -p runtime data && chmod 0775 runtime data  # 属主与 php-fpm / cron 相同
php think clear                                    # 清模板缓存（首次可跳过）
```

然后浏览器打开 **`https://panel.example.com/install.php`**，向导会：

1. **环境自检**：PHP ≥ 8.1、curl/openssl/pdo_sqlite 扩展、`data/` 与 `runtime/` 可写（不可写会自动尝试创建）
2. **管理员账号**：用户名（3-24 位，将作为面板唯一管理员）与 ≥8 位密码
3. **站点选项**：默认语言（中文/English）、是否信任 `X-Forwarded-Proto`（nginx 终止 TLS 时勾选）
4. **第一个 Nightscout 后端**：友好名称、地址（http/https）、该实例的 API_SECRET——可先「测试连接」再提交

提交成功后：SQLite 建库（schema v2）、随机生成 64 位十六进制加密种子并入库、后端密钥以 AES-256-GCM 加密落库、写入 `data/install.lock`。**此后安装器自动关闭**（再访问只显示「已安装」），未安装时所有面板页面 302 跳转到安装器。

第二个及以后的后端在 `/admin/users` 页面添加；删除后端前必须先迁走或删除其下用户。

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

`panel/data/` 需允许 php-fpm 与 cron 的同一用户读写（0600 的库文件 + 0770 目录），且**不能位于 Web 根目录内**：数据库、`install.lock` 与 `wal/shm` 文件都在那里。启用 OPcache。无需向 php-fpm pool 传入任何面板环境变量。

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
php -S 0.0.0.0:8090 -t public public/router.php

# 2. 浏览器打开 http://127.0.0.1:8090/install.php 完成安装向导
#    （Nightscout 地址填 http://127.0.0.1:1337，API_SECRET 与上面相同）

# 3. 另开一个终端跑同步（本地没有 cron 时手动触发）
php bin/sync.php
php bin/sync.php --profiles
```

内置服务器只用于开发；生产必须用 php-fpm。

## Nightscout 后端管理与一键迁移

全部在 `/admin/users` 页面维护：

- **后端列表**：友好名称（唯一，不区分大小写）、地址、用户数、启用状态；每行可「测试连接」（显示 Nightscout 版本与运行状态）、「停用/启用」（停用后注册页不再出现，已有账号同步暂停）。
- **添加/编辑**：编辑时 API_SECRET 留空 = 保持不变；密钥始终加密落库，页面不再显示。
- **删除保护**：仍有用户的后端不可删除，必须先迁移或删除这些用户。
- **一键迁移**（`/admin/migrate`）：选择源后端与目标后端 → 确认页列出受影响用户清单（可勾选「迁移完成后清理源端旧数据」，默认不勾选）→ 执行。迁移按账号**幂等**：
  1. 目标端重建身份：角色幂等创建；同名 subject 先删后建（**用户名以面板为准**）；Profile store 档案从源端原样搬过去
  2. 目标端清空该用户名下的旧数据（防重试重复）后，分页全量重传 entries / treatments / devicestatus（剔除 Mongo `_id`）
  3. 账号切换到目标后端（新 token 加密入库），本地镜像与游标清空，下次 cron 从新后端重灌——**不会双计数**
  4. 勾选清理时才删除源端数据与 subject/角色；清理失败可重跑补齐，已迁移账号不受影响
- 迁移在一次 HTTP 请求内同步执行（`set_time_limit(0)`）：账号多或历史长时可能持续数分钟，期间请保持页面打开；浏览器或反代超时导致的中断直接重跑即可——流程幂等，已迁移账号不受影响。
- 失败账号在结果页逐个显示原因，直接重试即可。

## 功能页

- `/register` 注册 → **选择 Nightscout 后端**（下拉显示友好名称）→ 自动创建 `store[<username>]` + 专属角色（`api:profile:<username>:read/write/create` + 各集合 create）+ subject，页面上直接展示发放的后端、地址与 token；无可用后端时注册关闭
- 导航只有 5 项（首页/报表/治疗/设置/管理），仪表盘顶部提供「记一笔」等快捷入口
- **设置页四标签**（`/profile`）：Profile 编辑 ｜ 方案历史 ｜ 提醒 ｜ 我的 API（专属地址、token 掩码、curl 示例、CGM 上传器配置指引）
- `/dashboard` 血糖趋势（ECharts，mmol/L）：趋势箭头 + Δ 变化 + IOB/COB/电量、校准血糖（mbg）叠加、任意历史日回看、3 点平滑；每 30 秒轮询本地镜像，数据延迟超过 10 分钟显示提示，越线时显示告警横幅
- `/reports` 统计报告（近 7/14/30/90 天）：TIR/TAR/TBR、血糖分布（5 档）、平均值/中位数/标准差/CV、GMI、低血糖事件（≥15 分钟）、数据覆盖率，以及落在本人 Profile 目标范围的占比
- `/export/entries.csv|json`、`/export/treatments.csv` 数据导出（`?days=` 夹在 1..90，CSV 带 UTF-8 BOM）
- `/settings/alerts` 个人提醒线：低/高阈值（存面板数据库），可同时开启浏览器桌面通知；阈值由同步任务在服务端评估，事件写入 `panel_alert_events`，页面轮询后通知并回执
- `/entry` 填写数据：手填血糖 + 全部治疗事件类型（20 种，表单字段由服务端注册表驱动）+ 活动记录
- `/treatments` 我的记录：列出自己的治疗记录，支持编辑与删除（归属 `enteredBy` 由服务端校验）
- `/admin/users` 后端管理（增删改/启停/连通测试）、用户管理、删除用户（联动删除 subject / 角色 / 镜像数据）；`/admin/migrate` 一键迁移

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
- 选择顺序：会话 → cookie → `Accept-Language` → 安装器写入的站点默认语言 → `zh_cn`
- 文案集中在 `panel/lang/zh_cn.php` 与 `panel/lang/en.php`（扁平 key → 文本，支持 `:name` 占位符）；控制器统一 `t('key')`，模板里无参数文案用 `{:t('key')}`、**带变量文案一律用 `{:t_html('key', [...])}`**（函数输出不经过模板默认转义）
- 新增文案只需在两个字典里各加一行；`php panel/tests/lang-test.php` 会校验两份字典 key 与占位符完全一致，并扫描 `app/` 与 `view/` 中用到的 key 是否都已定义

## 安全基线

- 所有响应带 `X-Content-Type-Options`、`X-Frame-Options: DENY`、`Referrer-Policy: same-origin` 与限制为本源的 CSP。
- 模板默认 `htmlentities` 转义；`|raw` 只允许控制器注入的 JSON 变量（白名单由 `views-xss-test.php` 强制）；动态列表用 `textContent` 构建。
- 后端 API_SECRET 与用户 token 全部 AES-256-GCM 加密落库；密钥种子由安装器随机生成、存于 `panel_settings`（库文件 0600 + 目录 0770）；`public/` 之下的 Web 根不包含任何源码或数据目录。
- `install.php` 安装完成即落锁关闭；未安装时全部页面跳转安装器，安装器带会话 CSRF 与提交限流。
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
php panel/tests/backends-test.php        # 后端注册表：CRUD、加密、删除保护
php panel/tests/migration-test.php       # 迁移分页游标与记录清洗（纯函数）
php panel/tests/install-test.php         # 安装向导：子进程跑全部场景
php panel/tests/views-xss-test.php       # 模板转义与 |raw 白名单
php panel/tests/http-test.php            # 路由矩阵（每个用例独立进程）
```

## 升级与回滚

- **本版本不兼容旧库**（schema v1）：旧 `panel.db` 会被明确拒绝并提示先备份删除，随后打开 `/install.php` 重新安装（旧库里的 API_SECRET / 用户 token 需重新发放或重装后端）。
- 升级：备份 `data/`（`panel.db`、`install.lock` 与 `-wal/-shm`）→ 拉代码 → `composer install --no-dev -o` → `php think clear` → 重启 php-fpm。
- 回滚：把代码切回上一个 release、恢复备份的 `data/` 即可；若曾改动 nginx，请把 `root` 一并回退。
- 环境变量（`NIGHTSCOUT_URL`、`NIGHTSCOUT_ADMIN_SECRET`、`PANEL_SECRET_KEY`、`PANEL_DB`、`PANEL_TRUST_PROXY`）已全部废除，升级后请删除相关 php-fpm pool / systemd 配置项。
