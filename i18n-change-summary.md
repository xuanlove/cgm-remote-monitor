# Nightscout 多语言改造变更摘要

> 分支 `trae/agent-ttY8mE` → `master`，提交 `c671a03`（41 files，+1374/−159），2026-11-12

## 一、目标与结果

将 Nightscout（cgm-remote-monitor v15.0.9）的界面语言默认切换为**简体中文**，并完成全站用户可见文本的 i18n 接入：默认界面全中文，缺失 key 自动回退英文原文，`LANGUAGE` 环境变量与浏览器端语言选择仍可覆盖默认值。

## 二、分阶段实施

| 阶段 | 内容 | 状态 |
|---|---|---|
| Phase 1 | 默认语言改中文（`lib/settings.js`），验证优先级链：浏览器 localStorage > `LANGUAGE` env > 默认值 | ✅ |
| Phase 2 | 全量盘点 638 条硬编码文本，产出 [i18n-hardcoded-audit.md](i18n-hardcoded-audit.md) | ✅ |
| P0 | 补齐 zh_CN 缺失的 2 个已有 key（profile-editor 校验提示） | ✅ |
| Phase 3 | views 视图层接入（41 条）：服务端 EJS + translate class + aria-label 扩展 | ✅ |
| Phase 4 | 客户端/插件 JS 层接入（~110 处包裹 + 渲染点接线 + key 补齐） | ✅ |
| Phase 5 | report/admin 插件接入（14 处包裹 + 元数据 key 补齐） | ✅ |
| Phase 6 | 服务端 console/API 错误文案——按决策保留英文 | ⏭️ 跳过 |
| Phase 7 | 翻译一致性守卫测试挂进 CI | ✅ |

## 三、机制层改动（3 处）

1. **`lib/settings.js`**：默认 `language: 'zh_cn'`；`lib/language.js` init 保持加载 en 作为回退源语言（`set()` 不重载翻译文件，运行时由 server.js 的 `set(env.settings.language)` + `loadLocalization()` 顺序驱动）
2. **`lib/language.js` DOMtranslate**：新增 `$('[aria-label]')` 属性翻译通道（仅写属性、容器安全），一次性覆盖主界面与工具栏全部无障碍标签
3. **`lib/server/app.js`**：`app.locals.translate = ctx.language.translate`，使 error.html / frame.html / clock-config.html 等无客户端 bundle 的服务端渲染页面可用 `<%= locals.translate('...') %>`

## 四、接入范围

- **视图层**（6 文件）：error.html、frame.html、clock-config.html 服务端翻译；index.html / profileindex.html / toolbar.ejs 加 translate/titletranslate class；顺手修复 2 个存量 bug（`pe_status` 重复 class 属性导致漏译、`Admin ntofications` 拼写错误且 en.json 缺 key）
- **客户端/插件 JS**（19 文件，~97 处包裹）：拼接串按 `%1/%2` 参数化（如 `translate('Suggested Temp: %1U/hour for %2m', rate, duration)`），英文输出逐字节不变（pill 金标本测试验证）
- **渲染点接线**：`cob.js:124` 传入 translate、`openaps.js:336` 翻译循环状态标签（Enacted→已执行等）
- **翻译目录**：en/zh_CN/lt_LT 各新增 153/156/154 条，三目录 **869 key 双向 diff = 0**；zh 全部人工翻译，术语对齐存量约定（Profile→配置文件、Care Portal→服务面板、mins→分）

## 五、有意保留英文

- 内部标识符与通知分组码（`MetricNow`、`BAGE` 等）、事件类型 val 码（存库值，做相等比较）
- HTTP/.ajax 常量（POST/Bearer）、console 日志（mmconnect 弃用警告、maker 错误）
- 方向码（显示为箭头字符）、国际单位（mg/dL、mmol/L）、法律条目（AGPL/Copyright）、产品名、配置串 `cy10`
- `'Loop'`（iob.js 存在 `=== 'Loop'` 比较点）

## 六、关键技术决策

1. **`translateCI` 后匹配覆盖**：勿新增 `'HIGH'`/`'LOW'` 泛化 key——会按插入顺序遮蔽 ar2/simplealarms 现有 `高血糖`/`低血糖` 词汇；index.js 大字显示改用 `{ci:true}` 查询
2. **key 素材禁止双重包裹**：下游已 `translate()` 的字符串（validate.js 校验消息、careportal 事件名）保持原样作为 key，否则破坏 `eventType === 'Combo Bolus'` 类比较逻辑
3. **lt_LT 对齐约定**：上游测试强制立陶宛语目录与英文目录 key 对齐（language.test.js:113），新增 key 须以英文 identity 值同步镜像
4. **服务端 vs 客户端翻译路径**：clock/error 页无 DOMtranslate，必须走服务端 EJS；动态渲染内容必须走 `translate()` 调用而非 translate class

## 七、验证结果

- 翻译目录三向对齐：en 869 / zh 869 / lt 869，双向 diff 0
- 全量单元测试 **466 passing**（6 个失败均为预存/环境问题：verifyauth 需 MongoDB、ar2 并行状态泄漏，stash 基线确认与改动无关）
- pill 金标本逐字节一致；eslint 0 错误；3 个服务端模板 EJS 渲染冒烟通过
- 一致性守卫破坏性自检：注入假 key 被准确拦截

## 八、防漂移守卫（Phase 7）

`tests/translation-consistency.test.js`（已挂进 `test:unit` / `test:unit:ci` / `npm test` / `test-ci`）：

1. zh_CN 覆盖 en 全部 key（缺失即报错并列出 key）
2. zh_CN 无 en 之外的孤儿 key
3. zh_CN 值全部为非空字符串
4. 占位符（`%N`/`{N}`）与英文源完全一致

后续新增可翻译文案时，只需同步在 en.json（identity）与 zh_CN.json（译文）追加，守卫测试会拦截遗漏。
