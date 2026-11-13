# NightPanel — Nightscout 多用户 PHP 面板

以 Nightscout 为纯后端的多用户面板。每个注册用户在 Nightscout 内获得一个**以用户名命名的 Profile 档案**（`store[<username>]`）与一个**对象级受限 token**，只能读写自己的档案并上传自己的数据，无法查看他人数据。

## 架构要点

- 单 Nightscout 实例承载多用户；隔离由三层实现：
  1. **Nightscout 权限改造**（本仓库内）：`api:profile:<name>:read/write` 作用域权限——token 直读 profile 只返回 `store[<name>]`，写入被服务端强制合并/剥离（见 `lib/api/profile/index.js`、`lib/authorization/index.js`）
  2. **面板归属过滤**：entries 按 `device=<username>`、treatments/activity 按 `enteredBy=<username>` 过滤，写入时强制注入归属
  3. **最小 token 权限**：用户 token 只有 profile 作用域读写 + 各集合 create（无任何 read）
- 面板发actions放：Nightscout 地址 + 用户 token + 面板网关说明（/myapi 页）

## 依赖

- PHP 8+（curl、openssl、pdo_sqlite、sqlite3）
- 本仓库的 Nightscout 实例（先 `npm ci && npm run bundle` 构建）

## 启动

```bash
# 1. Nightscout —— AUTH_DEFAULT_ROLES=denied 是安全前提（否则默认 readable 角色会泄漏读权限）
CUSTOMCONNSTR_mongo="mongodb://127.0.0.1:27017/nsdb" \
API_SECRET="<你的密钥>" \
AUTH_DEFAULT_ROLES="denied" \
INSECURE_USE_HTTP=true PORT=1337 node lib/server/server.js

# 2. 面板
cd panel
PANEL_ADMIN_USER=admin PANEL_ADMIN_PASS=<初始密码> \
PANEL_SECRET_KEY=<32位随机串> \
NIGHTSCOUT_URL=http://127.0.0.1:1337 \
NIGHTSCOUT_ADMIN_SECRET=<与上面相同的API_SECRET> \
php -S 0.0.0.0:8090 router.php
```

首次访问会自动创建 SQLite（`panel/data/panel.db`）与初始 admin（凭据打印在日志中）。

## 环境变量

| 变量 | 说明 |
|---|---|
| `NIGHTSCOUT_URL` | Nightscout 地址（也可在 /admin/users 页配置） |
| `NIGHTSCOUT_ADMIN_SECRET` | Nightscout API_SECRET 原文（面板用于管理 API） |
| `PANEL_ADMIN_USER` / `PANEL_ADMIN_PASS` | 初始管理员（仅首次建库时生效） |
| `PANEL_SECRET_KEY` | token/密钥加密密钥；未设置时自动生成并落库 |

## 功能页

- `/register` 注册 → 自动创建 `store[<username>]` + 专属角色（`api:profile:<username>:read/write/create` + 各集合 create）+ subject，页面上直接展示发放的地址与 token
- `/dashboard` 血糖趋势（ECharts，mmol/L）：趋势箭头 + Δ 变化 + IOB/COB/电量、校准血糖（mbg）叠加、任意历史日回看、3 点平滑
- `/profile` 编辑当前方案的配置档（基础率/敏感系数/目标范围/DIA/碳水速率）——以用户 token 直连提交，Nightscout 服务端强制作用域
- `/profile/plans` 治疗方案版本：按 startDate 倒序列出，当前生效 / 历史；「启用此方案」以该版本内容写入一条新记录；「保存为新版本」快照当前方案；共用记录与他人档案不可删除
- `/entry` 填写数据：手填血糖 + 全部治疗事件类型（大剂量/碳水/监测/临时/护理共 20 种，表单字段由服务端注册表驱动）+ 活动记录
- `/treatments` 我的记录：列出自己的治疗记录，支持编辑与删除（归属 enteredBy 由服务端校验，无法触达他人记录）
- `/myapi` 专属地址、token（掩码）、curl 示例、CGM 上传器配置指引
- `/admin/users` 用户管理、Nightscout 连接状态、删除用户（联动删除 subject）

### 治疗事件类型

事件类型与字段的唯一定义处是 `lib/TreatmentTypes.php`：表单由此生成，控制器也据此校验。新增类型只需在 `types()` 里加一行并复用 `fields()` 中的字段，表单与校验自动跟上；未登记的类型不会写入 Nightscout。

## 测试

Nightscout 权限改造的单元测试：`tests/profile-scoped.test.js`

```bash
npx env-cmd -f ./tests/ci.test.env npx mocha --timeout 30000 --require ./tests/hooks.js --exit ./tests/profile-scoped.test.js
```
