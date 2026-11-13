# NightPanel — Nightscout 多用户 PHP 面板

以 Nightscout 为纯后端的多用户面板。每个注册用户在 Nightscout 内获得一个**以用户名命名的 Profile 档案**（`store[<username>]`）与一个**对象级受限 token**，只能读写自己的档案并上传自己的数据，无法查看他人数据。

## 架构要点

- 单 Nightscout 实例承载多用户；隔离由三层实现：
  1. **Nightscout 权限改造**（本仓库内）：`api:profile:<name>:read/write` 作用域权限——token 直读 profile 只返回 `store[<name>]`，写入被服务端强制合并/剥离（见 `lib/api/profile/index.js`、`lib/authorization/index.js`）
  2. **面板归属过滤**：entries 按 `device=<username>`、treatments/activity 按 `enteredBy=<username>` 过滤，写入时强制注入归属
  3. **最小 token 权限**：用户 token 只有 profile 作用域读写 + 各集合 create（无任何 read）
- 面板发放：Nightscout 地址 + 用户 token + 面板网关说明（/myapi 页）

## 依赖

- PHP 8+（curl、openssl、pdo_sqlite、sqlite3）
- 本仓库的 Nightscout 实例（先 `npm ci && npm run bundle` 构建）

## 运行模式：Web 只读本地镜像，计划任务拉取 Nightscout

```
浏览器 ──> nginx/Apache ──> php-fpm ──> SQLite 镜像（毫秒级，只读）
                              │
                              └─ 写入类操作 ──> Nightscout API（返回记录直接写回镜像）

cron/systemd timer ──> panel/bin/sync.php ──> Nightscout API
```

- Web 请求**不再直接访问 Nightscout**：`/data/*.json`、报告、导出、Profile/治疗列表全部读 `panel_entries` / `panel_treatments` / `panel_devicestatus` / `panel_profiles`。
- `bin/sync.php` 按用户增量拉取：每条流在 `panel_sync_state` 里保存毫秒游标，并回看 6 小时重叠窗口（CGM 补传不会漏），按 Nightscout `_id` 幂等 upsert。
- 实时性由 30 秒轮询替代原 SSE，不再长期占用 php-fpm worker。
- 写入（填写数据 / 治疗编辑）成功后，用 Nightscout 返回的记录直接更新本地镜像，请求内不再额外拉取整条流；Profile / 治疗方案的写路径**实时读取** Nightscout 当前文档（避免用陈旧镜像覆盖他人档案），成功后刷新一次 Profile 快照。
- 数据新鲜度：`/data/glucose.json` 返回 `sync.age_sec` 并带 `X-Data-Age` 头；仪表盘超过 10 分钟显示「数据延迟」。

### 生产部署（php-fpm + nginx）

```nginx
server {
    listen 80;
    server_name panel.example.com;
    root /var/www/cgm-remote-monitor/panel;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    location ~ ^/(data|lib|lang|tests|bin)/ { deny all; }
    location = /glucose-trend-demo.html { deny all; }
}
```

Apache 部署时，仓库根目录的 `panel/.htaccess` 提供同等拒绝规则（`data/`、`lib/`、`bin/`、`tests/`、`lang/` 及开发演示页）；内置开发服务器则由 `router.php` 拦截。

php-fpm 会清空环境变量，务必在 pool 配置里显式传入：

```ini
; /etc/php/8.3/fpm/pool.d/panel.conf
env[NIGHTSCOUT_URL] = http://127.0.0.1:1337
env[NIGHTSCOUT_ADMIN_SECRET] = <API_SECRET>
env[PANEL_SECRET_KEY] = <32 位随机串>
env[PANEL_DB] = /var/lib/nightpanel/panel.db
env[PANEL_TRUST_PROXY] = 1
```

`panel/data/`（或 `PANEL_DB` 所在目录）需允许 php-fpm 与 cron 的同一用户读写，且**不能位于 Web 根目录内**：数据库、`<db>.key`（密钥种子）与 `wal/shm` 文件都在那里。启用 OPcache。

### 计划任务

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

等价 systemd timer（推荐，日志交给 journald）：

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

运维探活（只读数据库，Nightscout 故障时同样可用）：

```bash
php panel/bin/health.php --stale=600   # 退出码 1 表示有用户数据过期/从未同步
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
PANEL_ADMIN_USER=admin PANEL_ADMIN_PASS=<初始密码> \
PANEL_SECRET_KEY=<32位随机串> \
NIGHTSCOUT_URL=http://127.0.0.1:1337 \
NIGHTSCOUT_ADMIN_SECRET=<与上面相同的API_SECRET> \
php -S 0.0.0.0:8090 router.php

# 另开一个终端跑同步（本地没有 cron 时手动触发）
php bin/sync.php
php bin/sync.php --profiles
```

首次访问会自动创建 SQLite（`panel/data/panel.db`）与初始 admin（凭据打印在日志中）。内置服务器只用于开发；生产必须用 php-fpm。

## 环境变量

| 变量 | 说明 |
|---|---|
| `NIGHTSCOUT_URL` | Nightscout 地址（也可在 /admin/users 页配置） |
| `NIGHTSCOUT_ADMIN_SECRET` | Nightscout API_SECRET 原文（面板用于管理 API） |
| `PANEL_ADMIN_USER` / `PANEL_ADMIN_PASS` | 初始管理员（仅首次建库时生效） |
| `PANEL_SECRET_KEY` | token/密钥加密密钥。未设置时自动生成并写入 `PANEL_DB` 同目录的 `<db>.key`（权限 0600），**绝不落在数据库内**——否则一份 `panel.db` 同时带走密文与密钥。从旧版本升级会自动把 `panel_settings.key_seed` 迁移到该文件。`ns_secret` 以 AES-256-GCM 加密存储（`enc:v1:` 前缀），旧版明文值仍可读取 |
| `PANEL_DB` | SQLite 路径（默认 `panel/data/panel.db`；生产建议放到独立可写目录） |
| `PANEL_TRUST_PROXY` | 设为 `1` 时信任 `X-Forwarded-Proto`（反向代理终止 TLS 时用于 `Secure` cookie）。未设置时仅当对端是回环/内网地址才信任 |

## 功能页

- `/register` 注册 → 自动创建 `store[<username>]` + 专属角色（`api:profile:<username>:read/write/create` + 各集合 create）+ subject，页面上直接展示发放的地址与 token
- `/dashboard` 血糖趋势（ECharts，mmol/L）：趋势箭头 + Δ 变化 + IOB/COB/电量、校准血糖（mbg）叠加、任意历史日回看、3 点平滑；每 30 秒轮询本地镜像，新读数自动重建图表，数据延迟超过 10 分钟显示提示，越线时显示告警横幅
- `/reports` 统计报告（近 7/14/30/90 天）：TIR/TAR/TBR、血糖分布（5 档）、平均值/中位数/标准差/CV、GMI、低血糖事件（≥15 分钟）、数据覆盖率，以及落在本人 Profile 目标范围的占比；数据来自本地镜像，页内可直接导出 CSV/JSON
- `/export/entries.csv|json`、`/export/treatments.csv` 数据导出（按 `?days=` 区间，CSV 带 UTF-8 BOM 可直接用 Excel 打开）
- `/settings/alerts` 个人提醒线：低/高阈值（存面板数据库，不写入 Nightscout），可同时开启浏览器桌面通知；阈值由同步任务在服务端评估（无需浏览器打开），事件写入 `panel_alert_events`，页面轮询后通知并回执
- `/profile` 编辑当前方案的配置档（基础率/敏感系数/目标范围/DIA/碳水速率）——以用户 token 直连提交，Nightscout 服务端强制作用域
- `/profile/plans` 治疗方案版本：按 startDate 倒序列出，当前生效 / 历史；「启用此方案」以该版本内容写入一条新记录；「保存为新版本」快照当前方案；共用记录与他人档案不可删除
- `/entry` 填写数据：手填血糖 + 全部治疗事件类型（大剂量/碳水/监测/临时/护理共 20 种，表单字段由服务端注册表驱动）+ 活动记录
- `/treatments` 我的记录：列出自己的治疗记录，支持编辑与删除（归属 enteredBy 由服务端校验，无法触达他人记录）
- `/myapi` 专属地址、token（掩码）、curl 示例、CGM 上传器配置指引
- `/admin/users` 用户管理、Nightscout 连接状态、删除用户（联动删除 subject）

### 用户 token 的权限

```
api:profile:<username>:read     读自己的 Profile 档案（他人 store 键被服务端剥离）
api:profile:<username>:write    更新自己的档案
api:profile:<username>:create   新建只含自己档案的治疗方案版本
api:entries:<username>:read     读自己设备上传的血糖（api v1 entries）
api:entries/treatments/activity/devicestatus:create   上传数据
```

作用域读只在 v1 entries 生效：`/api/v2/properties`、api3、data 端点仍要求完整的 `api:entries:read`，未授予该权限的 token 在这些入口得到 401，因此作用域不会被绕开。

### 治疗事件类型

事件类型与字段的唯一定义处是 `lib/TreatmentTypes.php`：表单由此生成，控制器也据此校验。新增类型只需在 `types()` 里加一行并复用 `fields()` 中的字段，表单与校验自动跟上；未登记的类型不会写入 Nightscout。

### 多语言

- 界面支持 **简体中文（默认）与 English**，导航右上角的语言按钮即时切换
- 选择顺序：会话中的选择 → 上次的 cookie → 浏览器 `Accept-Language` → 默认 `zh_cn`
- 文案集中在 `panel/lang/zh_cn.php` 与 `panel/lang/en.php`（扁平 key → 文本，支持 `:name` 占位符）；视图与控制器统一走 `t('key')`，JavaScript 用 `Lang::js([...])` 注入
- 图表、动态表单字段、CSV 表头、错误与成功提示都走同一套字典；`html lang` 与 JS 的日期格式随语言切换
- 新增文案只需在两个字典里各加一行；`php panel/tests/lang-test.php` 会校验两份字典 key 完全一致、占位符一致，并扫描代码中用到的 key 是否都已定义

## 安全基线

- 所有页面响应带 `X-Content-Type-Options`、`X-Frame-Options: DENY`、`Referrer-Policy: same-origin` 与限制为本源的 CSP（内联脚本/样式仍需 `'unsafe-inline'`）。
- 模板里的 JS 数据一律走 `js_json()`（`JSON_HEX_*`），动态列表用 `textContent` 构建，Nightscout 返回的备注不会变成可执行 HTML。
- `ns_secret` 加密落库，密钥种子存放在数据库之外（`<db>.key`，0600）；`data/`、`lib/` 等目录在 nginx / Apache / 内置服务器三处都拒绝直接访问。
- 登录按客户端地址 20 次/15 分钟、注册 10 次/小时限流（`panel_rate_limits`，IP 以 SHA-256 存储）。
- 退出登录是 POST + CSRF（`GET /logout` 返回 405），外部页面无法用一张图片把你登出。
- 导出接口强制 `Content-Disposition: attachment`；`/data/*.json` 仅登录用户可读。

## 测试

Nightscout 权限改造的单元测试：`tests/profile-scoped.test.js`

```bash
npx env-cmd -f ./tests/ci.test.env npx mocha --timeout 30000 --require ./tests/hooks.js --exit ./tests/profile-scoped.test.js ./tests/entries-scoped.test.js
```

面板统计、提醒与文案的自检（无需任何依赖）：

```bash
php panel/tests/glucose-stats-test.php
php panel/tests/alerts-test.php
php panel/tests/lang-test.php
php panel/tests/mirror-test.php    # 镜像表结构、按用户隔离、游标/新鲜度、告警事件、密钥加密、限流
php panel/tests/views-xss-test.php # 视图 XSS 回归（js_json 转义、DOM 文本节点）
```
