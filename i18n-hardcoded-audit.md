# i18n 硬编码文本盘点报告（Phase 2）

> **进度（Phase 5 已完成）**：report_plugins + admin_plugins 已接入翻译（14 处包裹 + 8 个 admin 元数据 key 补齐：cleanprofiledb/daterangedelete 的 label/name/description/buttonLabel/confirmText）。en/zh/lt = **869/869/869 全对齐**（755 → 854 → 869）。
>
> **全部进度**：Phase 1 默认中文 ✅ / Phase 2 盘点 ✅ / P0 缺译补齐 ✅ / Phase 3 views HTML ✅ / Phase 4 客户端+插件 JS ✅ / Phase 5 report+admin ✅ / **Phase 7 校验挂 CI ✅**（tests/translation-consistency.test.js，4 项守卫：zh↔en key 双向对齐、空值、占位符 %N 一致；已纳入 test:unit / test:unit:ci / npm test / test-ci）。Phase 6 服务端文本（console/API 错误）按既定决策保留英文，跳过。**项目多语言改造全部完成。**
>
> **Phase 4 记录**：19 个文件约 97 处 `translate()` 包裹，cob/openaps 渲染点接线，`DOMtranslate` aria-label 支持。保留英文：内部标识符（MetricNow、BAGE 等分组码）、事件 val 码（存库比较值）、HTTP 常量、console 日志、方向码、'Loop'（iob.js 比较点）、产品名。技术要点：`translateCI` 后匹配覆盖——勿新增 'HIGH'/'LOW' 泛化 key；key 素材（下游已 translate）不得双重包裹。
>
> **历史进度（Phase 3）**：views HTML 层已全部接入翻译（服务端 EJS 用 `locals.translate`，客户端用 `.translate`/`.titletranslate` class，aria-label 经 `DOMtranslate` 扩展统一处理）。有意保留英文：mg/dL、mmol/L（国际单位）、AGPL/Copyright（法律条目）、产品名、`cy10`（配置串）。

> 扫描范围：views/*.html(+partials/clockviews)、lib/plugins、lib/report_plugins、lib/admin_plugins、lib/client、lib/client-core、lib/server、static/js。已剔除 lib/client/d3locales.js（本地化数据，102 条误报）。

## 概览

| 类别 | 条数 | 说明 |
|---|---|---|
| en/zh 现有 key 差异 | 2 | zh_CN 缺失的已有 key（P0 立即修复）|
| views HTML 未翻译文本 | 47 | 静态页面文本+属性（P1）|
| 插件 name/label 元数据 | 102 | UI 显示的插件名（P2）|
| 客户端/插件层 JS 文本 | 403 | 用户可见文案（P3，工作量主体）|
| 服务端文本 | 86 | 多为日志/错误消息（P4，默认保留英文）|
| **合计** | 638 | 其中 228 条 key 已存在于 en.json（只需包 translate()）|

## P0 — zh_CN 缺失的现有 key

- `Enter every profile value before saving.`（来源 lib/client-core/profile-editor）
- `Your profile could not be loaded. Reload before editing.`（来源 lib/client-core/profile-editor）
- 建议译文：保存前请填写所有配置值。 / 无法加载您的配置，请刷新后再编辑。

## P1 — views HTML 未翻译文本

| 位置 | 文本 | en.json |
|---|---|---|
| views/clockviews/clock-config.html:2 | Clock view configurator | **新增** |
| views/clockviews/clock-config.html:5 | Background type | **新增** |
| views/clockviews/clock-config.html:7 | Color | 已有 key |
| views/clockviews/clock-config.html:8 | Black | **新增** |
| views/clockviews/clock-config.html:12 | Show SGV age | **新增** |
| views/clockviews/clock-config.html:14 | Always | 已有 key |
| views/clockviews/clock-config.html:15 | Only after threshold | **新增** |
| views/clockviews/clock-config.html:18 | SGV age threshold: | **新增** |
| views/clockviews/clock-config.html:18 | minutes | 已有 key |
| views/clockviews/clock-config.html:20 | Size: | **新增** |
| views/clockviews/clock-config.html:27 | Open my clock view! | **新增** |
| views/clockviews/clock-config.html:27 | cy10 | **新增** |
| views/error.html:50 | Oops - Nightscout is having trouble | **新增** |
| views/error.html:52 | Don't panic, we can work this out! This happens to the best of us. | **新增** |
| views/error.html:53 | Check the errors below and then refer to the | **新增** |
| views/error.html:54 | troubleshooting documentation | **新增** |
| views/error.html:56 | Errors occurred during startup: | **新增** |
| views/foodindex.html:51 | (ID: | **新增** |
| views/foodindex.html:65 | : [g] | **新增** |
| views/frame.html:20 | Nightscout multiframe view | **新增** |
| views/index.html:0 (aria-label) | Notification | **新增** |
| views/index.html:0 (aria-label) | cancel | **新增** |
| views/index.html:0 (aria-label) | Blood Sugar | **新增** |
| views/index.html:0 (aria-label) | Chart | **新增** |
| views/index.html:0 (aria-label) | Settings | 已有 key |
| views/index.html:0 (title) | Use IOB in calculation | 已有 key |
| views/index.html:4 | head | **新增** |
| views/index.html:112 | Loading the client | **新增** |
| views/index.html:187 | mg/dL | **新增** |
| views/index.html:188 | mmol/L | **新增** |
| views/index.html:308 | version | **新增** |
| views/index.html:311 | License: | **新增** |
| views/index.html:311 | AGPL | **新增** |
| views/index.html:312 | Copyright © 2017 | **新增** |
| views/index.html:312 | Nightscout contributors | **新增** |
| views/index.html:319 | CGM in the Cloud | **新增** |
| views/partials/toolbar.ejs:0 (aria-label) | Menu | **新增** |
| views/partials/toolbar.ejs:2 | Nightscout | **新增** |
| views/profileindex.html:0 (title) | Value for carbs with high glycemic index | **新增** |
| views/profileindex.html:0 (title) | Value for carbs with medium glycemic index | **新增** |
| views/profileindex.html:0 (title) | Value for carbs with low glycemic index | **新增** |
| views/profileindex.html:0 (title) | minutes. Initial time before carbs are being absorbed. | **新增** |
| views/profileindex.html:130 | High: | **新增** |
| views/profileindex.html:131 | Medium: | **新增** |
| views/profileindex.html:132 | Low: | **新增** |
| views/profileindex.html:135 | Carbs absorption delay: [min] | **新增** |
| views/profileindex.html:161 | Not loaded | 已有 key |

## P2 — 插件 name/label 元数据

| 位置 | 文本 | en.json |
|---|---|---|
| lib/admin_plugins/cleanentriesdb.js:8 | Clean Mongo entries (glucose entries) database | 已有 key |
| lib/admin_plugins/cleanentriesdb.js:21 | Delete all documents from entries collection older than 180 days | 已有 key |
| lib/admin_plugins/cleanentriesdb.js:23 | Delete old documents | 已有 key |
| lib/admin_plugins/cleanprofiledb.js:7 | Clean Mongo profile database | **新增** |
| lib/admin_plugins/cleanprofiledb.js:19 | Delete old profile documents | **新增** |
| lib/admin_plugins/cleanprofiledb.js:20 | This task removes older documents from the profile collection while keeping the newest profile records. | **新增** |
| lib/admin_plugins/cleanprofiledb.js:21 | Delete old profile records | **新增** |
| lib/admin_plugins/cleanstatusdb.js:8 | Clean Mongo status database | 已有 key |
| lib/admin_plugins/cleanstatusdb.js:21 | Delete all documents from devicestatus collection | 已有 key |
| lib/admin_plugins/cleanstatusdb.js:23 | Delete all documents | 已有 key |
| lib/admin_plugins/cleanstatusdb.js:27 | Delete all documents from devicestatus collection older than 30 days | 已有 key |
| lib/admin_plugins/cleanstatusdb.js:29 | Delete old documents | 已有 key |
| lib/admin_plugins/cleantreatmentsdb.js:8 | Clean Mongo treatments database | 已有 key |
| lib/admin_plugins/cleantreatmentsdb.js:21 | Delete all documents from treatments collection older than 180 days | 已有 key |
| lib/admin_plugins/cleantreatmentsdb.js:23 | Delete old documents | 已有 key |
| lib/admin_plugins/daterangedelete.js:8 | Delete MongoDB records by date range | **新增** |
| lib/admin_plugins/daterangedelete.js:29 | Delete records from selected collections within a date range | **新增** |
| lib/admin_plugins/daterangedelete.js:31 | Delete records in date range | **新增** |
| lib/admin_plugins/futureitems.js:7 | Remove future items from mongo database | 已有 key |
| lib/admin_plugins/futureitems.js:19 | Find and remove treatments in the future | 已有 key |
| lib/admin_plugins/futureitems.js:20 | This task find and remove treatments in the future. | 已有 key |
| lib/admin_plugins/futureitems.js:21 | Remove treatments in the future | 已有 key |
| lib/admin_plugins/futureitems.js:25 | Find and remove entries in the future | 已有 key |
| lib/admin_plugins/futureitems.js:27 | Remove entries in the future | 已有 key |
| lib/admin_plugins/roles.js:7 | Roles - Groups of People, Devices, etc | 已有 key |
| lib/admin_plugins/roles.js:21 | Add new Role | 已有 key |
| lib/admin_plugins/subjects.js:21 | Add new Subject | 已有 key |
| lib/client-core/devicestatus/openaps.js:98 | Warning | 已有 key |
| lib/plugins/basalprofile.js:12 | Basal Profile | 已有 key |
| lib/plugins/batteryage.js:11 | Pump Battery Age | 已有 key |
| lib/plugins/bgnow.js:15 | BG Now | **新增** |
| lib/plugins/bolus.js:7 | Bolus | **新增** |
| lib/plugins/boluscalc.js:9 | Bolus Wizard | 已有 key |
| lib/plugins/boluswizardpreview.js:12 | Bolus Wizard Preview | 已有 key |
| lib/plugins/cannulaage.js:11 | Cannula Age | 已有 key |
| lib/plugins/careportal.js:7 | Care Portal | 已有 key |
| lib/plugins/careportal.js:21 | BG Check | 已有 key |
| lib/plugins/careportal.js:25 | Snack Bolus | 已有 key |
| lib/plugins/careportal.js:29 | Meal Bolus | 已有 key |
| lib/plugins/careportal.js:33 | Correction Bolus | 已有 key |
| lib/plugins/careportal.js:37 | Carb Correction | 已有 key |
| lib/plugins/careportal.js:41 | Combo Bolus | 已有 key |
| lib/plugins/careportal.js:45 | Announcement | 已有 key |
| lib/plugins/careportal.js:49 | Note | 已有 key |
| lib/plugins/careportal.js:53 | Question | 已有 key |
| lib/plugins/careportal.js:57 | Exercise | 已有 key |
| lib/plugins/careportal.js:61 | Pump Site Change | 已有 key |
| lib/plugins/careportal.js:65 | CGM Sensor Start | 已有 key |
| lib/plugins/careportal.js:69 | CGM Sensor Insert | 已有 key |
| lib/plugins/careportal.js:73 | CGM Sensor Stop | 已有 key |
| lib/plugins/careportal.js:77 | Pump Battery Change | 已有 key |
| lib/plugins/careportal.js:81 | Insulin Cartridge Change | 已有 key |
| lib/plugins/careportal.js:85 | Temp Basal Start | 已有 key |
| lib/plugins/careportal.js:89 | Temp Basal End | 已有 key |
| lib/plugins/careportal.js:93 | Profile Switch | 已有 key |
| lib/plugins/direction.js:9 | BG direction | **新增** |
| lib/plugins/errorcodes.js:9 | Dexcom Error Codes | **新增** |
| lib/plugins/errorcodes.js:50 | CGM Error Code | **新增** |
| lib/plugins/insulinage.js:10 | Insulin Age | 已有 key |
| lib/plugins/loop.js:17 | Loop | **新增** |
| lib/plugins/loop.js:76 | Loop isn\ | **新增** |
| lib/plugins/loop.js:142 | Temporary Override | **新增** |
| lib/plugins/loop.js:158 | Temporary Override Cancel | **新增** |
| lib/plugins/loop.js:173 | Remote Carbs Entry | **新增** |
| lib/plugins/loop.js:181 | Remote Bolus Entry | **新增** |
| lib/plugins/openaps.js:13 | OpenAPS | **新增** |
| lib/plugins/openaps.js:115 | Temporary Target | 已有 key |
| lib/plugins/openaps.js:130 | Temporary Target Cancel | 已有 key |
| lib/plugins/openaps.js:143 | OpenAPS Offline | 已有 key |
| lib/plugins/openaps.js:174 | OpenAPS isn\ | **新增** |
| lib/plugins/override.js:6 | Override | **新增** |
| lib/plugins/profile.js:9 | Profile | 已有 key |
| lib/plugins/pump.js:17 | Pump | 已有 key |
| lib/plugins/pump.js:236 | Reservoir | **新增** |
| lib/plugins/rawbg.js:9 | Raw BG | 已有 key |
| lib/plugins/runtimestate.js:7 | Runtime state | **新增** |
| lib/plugins/sensorage.js:12 | Sensor Age | 已有 key |
| lib/plugins/simplealarms.js:9 | Simple Alarms | **新增** |
| lib/plugins/speech.js:13 | Speech | 已有 key |
| lib/plugins/timeago.js:13 | Timeago | **新增** |
| lib/plugins/treatmentnotify.js:13 | Treatment Notifications | **新增** |
| lib/plugins/treatmentnotify.js:70 | Snoozing alarms since there was a recent treatment | **新增** |
| lib/plugins/treatmentnotify.js:83 | Calibration | 已有 key |
| lib/plugins/upbat.js:11 | Uploader Battery | **新增** |
| lib/plugins/webhook.js:155 | Webhook Notifier | **新增** |
| lib/plugins/xdripjs.js:15 | CGM Status | **新增** |
| lib/report_plugins/calibrations.js:8 | Calibrations | 已有 key |
| lib/report_plugins/dailystats.js:5 | Daily Stats | 已有 key |
| lib/report_plugins/daytoday.js:10 | Day to day | 已有 key |
| lib/report_plugins/glucosedistribution.js:7 | Distribution | 已有 key |
| lib/report_plugins/hourlystats.js:7 | Hourly stats | 已有 key |
| lib/report_plugins/loopalyzer.js:10 | Loopalyzer | **新增** |
| lib/report_plugins/percentile.js:5 | Percentile Chart | 已有 key |
| lib/report_plugins/profiles.js:8 | Profiles | 已有 key |
| lib/report_plugins/success.js:7 | Weekly Distribution | 已有 key |
| lib/report_plugins/treatments.js:9 | Treatments | 已有 key |
| lib/report_plugins/weektoweek.js:18 | Week to week | 已有 key |
| lib/server/app.js:250 | Admin Tools | 已有 key |
| lib/server/app.js:255 | Food Editor | 已有 key |
| lib/server/app.js:260 | Profile Editor | 已有 key |
| lib/server/app.js:265 | Nightscout reporting | 已有 key |
| lib/server/server.js:79 | All Clear | **新增** |

## P3 — 客户端/插件层 JS 用户可见文本

| 位置 | 文本 | en.json |
|---|---|---|
| lib/admin_plugins/cleanentriesdb.js:8 | Clean Mongo entries (glucose entries) database | 已有 key |
| lib/admin_plugins/cleanentriesdb.js:21 | Delete all documents from entries collection older than 180 days | 已有 key |
| lib/admin_plugins/cleanentriesdb.js:22 | This task removes all documents from entries collection that are older than 180 days. Useful when uploader battery status is not properly updated. | 已有 key |
| lib/admin_plugins/cleanentriesdb.js:23 | Delete old documents | 已有 key |
| lib/admin_plugins/cleanentriesdb.js:24 | Delete old documents from entries collection? | 已有 key |
| lib/admin_plugins/cleanentriesdb.js:68 | DELETE | **新增** |
| lib/admin_plugins/cleanprofiledb.js:7 | Clean Mongo profile database | **新增** |
| lib/admin_plugins/cleanprofiledb.js:19 | Delete old profile documents | **新增** |
| lib/admin_plugins/cleanprofiledb.js:20 | This task removes older documents from the profile collection while keeping the newest profile records. | **新增** |
| lib/admin_plugins/cleanprofiledb.js:21 | Delete old profile records | **新增** |
| lib/admin_plugins/cleanprofiledb.js:22 | Delete old profile records from profile collection? | **新增** |
| lib/admin_plugins/cleanprofiledb.js:65 | DELETE | **新增** |
| lib/admin_plugins/cleanstatusdb.js:8 | Clean Mongo status database | 已有 key |
| lib/admin_plugins/cleanstatusdb.js:21 | Delete all documents from devicestatus collection | 已有 key |
| lib/admin_plugins/cleanstatusdb.js:22 | This task removes all documents from devicestatus collection. Useful when uploader battery status is not properly updated. | 已有 key |
| lib/admin_plugins/cleanstatusdb.js:23 | Delete all documents | 已有 key |
| lib/admin_plugins/cleanstatusdb.js:24 | Delete all documents from devicestatus collection? | 已有 key |
| lib/admin_plugins/cleanstatusdb.js:27 | Delete all documents from devicestatus collection older than 30 days | 已有 key |
| lib/admin_plugins/cleanstatusdb.js:28 | This task removes all documents from devicestatus collection that are older than 30 days. Useful when uploader battery status is not properly updated. | 已有 key |
| lib/admin_plugins/cleanstatusdb.js:29 | Delete old documents | 已有 key |
| lib/admin_plugins/cleanstatusdb.js:30 | Delete old documents from devicestatus collection? | 已有 key |
| lib/admin_plugins/cleanstatusdb.js:66 | DELETE | **新增** |
| lib/admin_plugins/cleantreatmentsdb.js:8 | Clean Mongo treatments database | 已有 key |
| lib/admin_plugins/cleantreatmentsdb.js:21 | Delete all documents from treatments collection older than 180 days | 已有 key |
| lib/admin_plugins/cleantreatmentsdb.js:22 | This task removes all documents from treatments collection that are older than 180 days. Useful when uploader battery status is not properly updated. | 已有 key |
| lib/admin_plugins/cleantreatmentsdb.js:23 | Delete old documents | 已有 key |
| lib/admin_plugins/cleantreatmentsdb.js:24 | Delete old documents from treatments collection? | 已有 key |
| lib/admin_plugins/cleantreatmentsdb.js:69 | DELETE | **新增** |
| lib/admin_plugins/daterangedelete.js:8 | Delete MongoDB records by date range | **新增** |
| lib/admin_plugins/daterangedelete.js:29 | Delete records from selected collections within a date range | **新增** |
| lib/admin_plugins/daterangedelete.js:30 | This task removes documents from selected MongoDB collections for a specified date range. You can delete from a single collection or from all collections at once. | **新增** |
| lib/admin_plugins/daterangedelete.js:31 | Delete records in date range | **新增** |
| lib/admin_plugins/daterangedelete.js:417 | DELETE | **新增** |
| lib/admin_plugins/futureitems.js:7 | Remove future items from mongo database | 已有 key |
| lib/admin_plugins/futureitems.js:19 | Find and remove treatments in the future | 已有 key |
| lib/admin_plugins/futureitems.js:20 | This task find and remove treatments in the future. | 已有 key |
| lib/admin_plugins/futureitems.js:21 | Remove treatments in the future | 已有 key |
| lib/admin_plugins/futureitems.js:25 | Find and remove entries in the future | 已有 key |
| lib/admin_plugins/futureitems.js:26 | This task find and remove CGM data in the future created by uploader with wrong date/time. | 已有 key |
| lib/admin_plugins/futureitems.js:27 | Remove entries in the future | 已有 key |
| lib/admin_plugins/futureitems.js:99 | DELETE | **新增** |
| lib/admin_plugins/roles.js:7 | Roles - Groups of People, Devices, etc | 已有 key |
| lib/admin_plugins/roles.js:20 | permission is a wildcard, permissions are a hierarchy using | **新增** |
| lib/admin_plugins/roles.js:21 | Add new Role | 已有 key |
| lib/admin_plugins/roles.js:38 | POST | **新增** |
| lib/admin_plugins/roles.js:58 | DELETE | **新增** |
| lib/admin_plugins/roles.js:161 | Delete this role | **新增** |
| lib/admin_plugins/subjects.js:20 | Each subject will have a unique access token and 1 or more roles. Click on the access token to open a new view with the selected subject, this secret link can then be shared. | **新增** |
| lib/admin_plugins/subjects.js:21 | Add new Subject | 已有 key |
| lib/admin_plugins/subjects.js:37 | POST | **新增** |
| lib/admin_plugins/subjects.js:57 | DELETE | **新增** |
| lib/client-core/careportal/confirm-text.js:41 | Temporary Target | 已有 key |
| lib/client-core/careportal/confirm-text.js:70 | Combo Bolus | 已有 key |
| lib/client-core/careportal/normalize-treatment.js:92 | Temp Basal | 已有 key |
| lib/client-core/careportal/normalize-treatment.js:95 | Temporary Target Cancel | 已有 key |
| lib/client-core/careportal/normalize-treatment.js:97 | Temporary Target | 已有 key |
| lib/client-core/careportal/normalize-treatment.js:101 | Combo Bolus | 已有 key |
| lib/client-core/careportal/validate.js:31 | Temporary Target | 已有 key |
| lib/client-core/careportal/validate.js:34 | Please enter a valid value for both top and bottom target to save a Temporary Target | **新增** |
| lib/client-core/careportal/validate.js:51 | Temporary target high is too high | **新增** |
| lib/client-core/careportal/validate.js:56 | Temporary target low is too low | **新增** |
| lib/client-core/careportal/validate.js:61 | The low target must be lower than the high target and high target must be higher than the low target. | **新增** |
| lib/client-core/devicestatus/cob.js:74 | OpenAPS | **新增** |
| lib/client-core/devicestatus/cob.js:86 | Loop | **新增** |
| lib/client-core/devicestatus/openaps.js:98 | Warning | 已有 key |
| lib/client-core/devicestatus/openaps.js:105 | Not Enacted | **新增** |
| lib/client-core/devicestatus/openaps.js:109 | Enacted | **新增** |
| lib/client-core/devicestatus/openaps.js:113 | Looping | **新增** |
| lib/client-core/devicestatus/openaps.js:117 | Waiting | **新增** |
| lib/client-core/profile-editor/profiles.js:26 | New profile | **新增** |
| lib/client-core/profile-editor/records.js:10 | Default | 已有 key |
| lib/client/boluscalc.js:45 | TouchEvent | **新增** |
| lib/client/boluscalc.js:239 | Target BG range: | **新增** |
| lib/client/boluscalc.js:477 | Calculation not completed! | **新增** |
| lib/client/boluscalc.js:482 | Bolus Wizard | 已有 key |
| lib/client/boluscalc.js:545 | POST | **新增** |
| lib/client/boluscalc.js:813 | #bc_eventTime input:radio | **新增** |
| lib/client/browser-settings.js:139 | ` + translate(label) + ` | **新增** |
| lib/client/browser-settings.js:213 | #show-plugins input:checked | **新增** |
| lib/client/browser-settings.js:274 | Settings are disabled. | **新增** |
| lib/client/browser-settings.js:275 | Settings | 已有 key |
| lib/client/browser-utils.js:79 | TouchEvent | **新增** |
| lib/client/careportal.js:390 | Combo Bolus | 已有 key |
| lib/client/careportal.js:397 | POST | **新增** |
| lib/client/clock-client.js:211 | FortyFiveDown | 已有 key |
| lib/client/clock-client.js:211 | SingleDown | 已有 key |
| lib/client/clock-client.js:211 | DoubleDown | 已有 key |
| lib/client/clock-client.js:211 | TripleDown | **新增** |
| lib/client/clock-client.js:268 | Just now | **新增** |
| lib/client/clock-client.js:281 | NOT COMPUTABLE | **新增** |
| lib/client/clock-client.js:281 | NONE | **新增** |
| lib/client/hashauth.js:192 | NONE | **新增** |
| lib/client/hashauth.js:210 | FOUND | **新增** |
| lib/client/index.js:27 | Connecting to server | **新增** |
| lib/client/index.js:32 | Bearer | **新增** |
| lib/client/index.js:45 | It appears the server has crashed. Please go to Heroku or Azure and reboot the server. | **新增** |
| lib/client/index.js:70 | Nightscout is still starting and should be available within about 15 seconds. | **新增** |
| lib/client/index.js:81 | Connecting to Nightscout server failed, retrying every 5 seconds | **新增** |
| lib/client/index.js:118 | Loading language file | **新增** |
| lib/client/index.js:317 | .focus-range li[data-hours= | **新增** |
| lib/client/index.js:412 | Nightscout | **新增** |
| lib/client/index.js:547 | HIGH | **新增** |
| lib/client/index.js:748 | .audio.alarms audio. | **新增** |
| lib/client/index.js:785 | Alarm was muted (?mute=true) | **新增** |
| lib/client/index.js:823 | Time Ago | **新增** |
| lib/client/index.js:1042 | .focus-range li | **新增** |
| lib/client/index.js:1298 | Ok ok. | **新增** |
| lib/client/index.js:1303 | .audio.alarms audio | **新增** |
| lib/client/renderer.js:279 | BasalProfileStart | **新增** |
| lib/client/renderer.js:279 | ResultDailyTotal | **新增** |
| lib/client/renderer.js:279 | BGReceived | **新增** |
| lib/client/renderer.js:285 | Temp Basal | 已有 key |
| lib/client/renderer.js:285 | Profile Switch | 已有 key |
| lib/client/renderer.js:285 | Combo Bolus | 已有 key |
| lib/client/renderer.js:285 | Temporary Target | 已有 key |
| lib/client/renderer.js:371 | Exercise | 已有 key |
| lib/client/renderer.js:372 | Violet | **新增** |
| lib/client/renderer.js:373 | Note | 已有 key |
| lib/client/renderer.js:374 | Salmon | **新增** |
| lib/client/renderer.js:583 | ); // One international unit of insulin (1 IU) is shown as | **新增** |
| lib/client/renderer.js:776 | Move | 已有 key |
| lib/client/renderer.js:788 | Remove insulin | 已有 key |
| lib/client/renderer.js:790 | Remove carbs | 已有 key |
| lib/client/renderer.js:792 | Remove | 已有 key |
| lib/client/renderer.js:794 | Move insulin | 已有 key |
| lib/client/renderer.js:796 | Move carbs | 已有 key |
| lib/client/renderer.js:1019 | 0px 0px 10px rgba(0, 0, 0, 1) | **新增** |
| lib/plugins/alexa.js:89 | PlainText | **新增** |
| lib/plugins/alexa.js:93 | Simple | 已有 key |
| lib/plugins/ar2.js:185 | MetricNow | **新增** |
| lib/plugins/basalprofile.js:12 | Basal Profile | 已有 key |
| lib/plugins/basalprofile.js:63 | Timezone not set in profile | **新增** |
| lib/plugins/basalprofile.js:153 | Status | 已有 key |
| lib/plugins/basalprofile.js:158 | MetricNow | **新增** |
| lib/plugins/batteryage.js:11 | Pump Battery Age | 已有 key |
| lib/plugins/batteryage.js:98 | Change pump battery soon | **新增** |
| lib/plugins/batteryage.js:119 | BAGE | **新增** |
| lib/plugins/bgnow.js:15 | BG Now | **新增** |
| lib/plugins/bgnow.js:275 | MetricNow | **新增** |
| lib/plugins/bolus.js:7 | Bolus | **新增** |
| lib/plugins/boluscalc.js:9 | Bolus Wizard | 已有 key |
| lib/plugins/boluswizardpreview.js:12 | Bolus Wizard Preview | 已有 key |
| lib/plugins/boluswizardpreview.js:19 | Missing need a treatment profile | **新增** |
| lib/plugins/boluswizardpreview.js:21 | Missing sens, target_high, or target_low treatment profile fields | **新增** |
| lib/plugins/boluswizardpreview.js:25 | Missing IOB property | **新增** |
| lib/plugins/cannulaage.js:11 | Cannula Age | 已有 key |
| lib/plugins/cannulaage.js:98 | Change cannula soon | 已有 key |
| lib/plugins/cannulaage.js:119 | CAGE | 已有 key |
| lib/plugins/careportal.js:7 | Care Portal | 已有 key |
| lib/plugins/careportal.js:20 | BG Check | 已有 key |
| lib/plugins/careportal.js:24 | Snack Bolus | 已有 key |
| lib/plugins/careportal.js:28 | Meal Bolus | 已有 key |
| lib/plugins/careportal.js:32 | Correction Bolus | 已有 key |
| lib/plugins/careportal.js:36 | Carb Correction | 已有 key |
| lib/plugins/careportal.js:40 | Combo Bolus | 已有 key |
| lib/plugins/careportal.js:44 | Announcement | 已有 key |
| lib/plugins/careportal.js:48 | Note | 已有 key |
| lib/plugins/careportal.js:52 | Question | 已有 key |
| lib/plugins/careportal.js:56 | Exercise | 已有 key |
| lib/plugins/careportal.js:60 | Site Change | **新增** |
| lib/plugins/careportal.js:61 | Pump Site Change | 已有 key |
| lib/plugins/careportal.js:64 | Sensor Start | 已有 key |
| lib/plugins/careportal.js:65 | CGM Sensor Start | 已有 key |
| lib/plugins/careportal.js:68 | Sensor Change | **新增** |
| lib/plugins/careportal.js:69 | CGM Sensor Insert | 已有 key |
| lib/plugins/careportal.js:72 | Sensor Stop | **新增** |
| lib/plugins/careportal.js:73 | CGM Sensor Stop | 已有 key |
| lib/plugins/careportal.js:76 | Pump Battery Change | 已有 key |
| lib/plugins/careportal.js:80 | Insulin Change | **新增** |
| lib/plugins/careportal.js:81 | Insulin Cartridge Change | 已有 key |
| lib/plugins/careportal.js:84 | Temp Basal Start | 已有 key |
| lib/plugins/careportal.js:88 | Temp Basal End | 已有 key |
| lib/plugins/careportal.js:92 | Profile Switch | 已有 key |
| lib/plugins/cob.js:293 | MetricNow | **新增** |
| lib/plugins/dbsize.js:90 | Database Size | 已有 key |
| lib/plugins/dbsize.js:137 | MetricNow | **新增** |
| lib/plugins/direction.js:9 | BG direction | **新增** |
| lib/plugins/direction.js:32 | CGM ERROR | **新增** |
| lib/plugins/direction.js:66 | NOT COMPUTABLE | **新增** |
| lib/plugins/direction.js:67 | RATE OUT OF RANGE | **新增** |
| lib/plugins/errorcodes.js:9 | Dexcom Error Codes | **新增** |
| lib/plugins/errorcodes.js:50 | CGM Error Code | **新增** |
| lib/plugins/insulinage.js:10 | Insulin Age | 已有 key |
| lib/plugins/insulinage.js:114 | IAGE | 已有 key |
| lib/plugins/iob.js:79 | Loop | **新增** |
| lib/plugins/iob.js:122 | OpenAPS | **新增** |
| lib/plugins/iob.js:136 | MM Connect | **新增** |
| lib/plugins/iob.js:275 | Status | 已有 key |
| lib/plugins/iob.js:280 | MetricNow | **新增** |
| lib/plugins/loop.js:17 | Loop | **新增** |
| lib/plugins/loop.js:77 | Last Loop: | **新增** |
| lib/plugins/loop.js:113 | POST | **新增** |
| lib/plugins/loop.js:141 | Temporary Override | **新增** |
| lib/plugins/loop.js:157 | Temporary Override Cancel | **新增** |
| lib/plugins/loop.js:172 | Remote Carbs Entry | **新增** |
| lib/plugins/loop.js:180 | Remote Bolus Entry | **新增** |
| lib/plugins/loop.js:209 | Suggested Temp: | **新增** |
| lib/plugins/loop.js:209 | U/hour for | **新增** |
| lib/plugins/loop.js:260 | BLE RSSI: | **新增** |
| lib/plugins/loop.js:264 | Pump RSSI: | **新增** |
| lib/plugins/loop.js:281 | Automatic Bolus | **新增** |
| lib/plugins/loop.js:284 | (Temp Basal Canceled) | **新增** |
| lib/plugins/loop.js:287 | Temp Basal Canceled | **新增** |
| lib/plugins/loop.js:289 | Temp Basal Started | **新增** |
| lib/plugins/loop.js:314 | , Basal IOB | **新增** |
| lib/plugins/loop.js:348 | , Predicted Min-Max BG: | **新增** |
| lib/plugins/loop.js:352 | , Eventual BG: | **新增** |
| lib/plugins/loop.js:364 | , Recommended Bolus: | **新增** |
| lib/plugins/loop.js:451 | Loop Forecasts | **新增** |
| lib/plugins/loop.js:516 | MetricNow | **新增** |
| lib/plugins/loop.js:520 | LastLoop | **新增** |
| lib/plugins/maker.js:28 | All Clear | **新增** |
| lib/plugins/maker.js:46 | No event name found | **新增** |
| lib/plugins/maker.js:48 | No event level found | **新增** |
| lib/plugins/mmconnect.js:119 | The legacy MiniMed CareLink bridge (MMCONNECT_* settings) is reported not to work and will be removed in a later release. | **新增** |
| lib/plugins/mmconnect.js:120 | Move to Nightscout Connect: add connect to ENABLE and set CONNECT_SOURCE=minimedcarelink, CONNECT_CARELINK_USERNAME, CONNECT_CARELINK_PASSWORD, CONNECT_CARELINK_REGION and CONNECT_COUNTRY_CODE | **新增** |
| lib/plugins/mmconnect.js:121 | (the two-letter country where the CareLink account was created; it cannot be worked out from MMCONNECT_SERVER). | **新增** |
| lib/plugins/mmconnect.js:122 | Once readings arrive through Nightscout Connect, remove the MMCONNECT_* settings and mmconnect from ENABLE. | **新增** |
| lib/plugins/openaps.js:13 | OpenAPS | **新增** |
| lib/plugins/openaps.js:22 | Color prediction lines | 已有 key |
| lib/plugins/openaps.js:110 | Manual | 已有 key |
| lib/plugins/openaps.js:114 | Temporary Target | 已有 key |
| lib/plugins/openaps.js:129 | Temporary Target Cancel | 已有 key |
| lib/plugins/openaps.js:142 | OpenAPS Offline | 已有 key |
| lib/plugins/openaps.js:175 | Last Loop: | **新增** |
| lib/plugins/openaps.js:218 | , Sensitivity Ratio: | **新增** |
| lib/plugins/openaps.js:236 | , Basal IOB | **新增** |
| lib/plugins/openaps.js:237 | , Bolus IOB | **新增** |
| lib/plugins/openaps.js:250 | Values | **新增** |
| lib/plugins/openaps.js:306 | , Temp Basal | **新增** |
| lib/plugins/openaps.js:306 | Canceled | 已有 key |
| lib/plugins/openaps.js:306 | Started | **新增** |
| lib/plugins/openaps.js:313 | Meal Assist: | **新增** |
| lib/plugins/openaps.js:378 | OpenAPS Forecasts | 已有 key |
| lib/plugins/openaps.js:411 | MetricNow | **新增** |
| lib/plugins/openaps.js:415 | LastLoop | **新增** |
| lib/plugins/override.js:6 | Override | **新增** |
| lib/plugins/override.js:39 | BG Target: | **新增** |
| lib/plugins/override.js:41 | BG Targets: | **新增** |
| lib/plugins/profile.js:9 | Profile | 已有 key |
| lib/plugins/pump.js:17 | Pump | 已有 key |
| lib/plugins/pump.js:167 | InsulinRemaining | **新增** |
| lib/plugins/pump.js:172 | PumpBattery | **新增** |
| lib/plugins/pump.js:176 | MetricNow | **新增** |
| lib/plugins/pump.js:203 | Last Clock | **新增** |
| lib/plugins/pump.js:211 | URGENT: Pump data stale | **新增** |
| lib/plugins/pump.js:214 | Warning, Pump data stale | **新增** |
| lib/plugins/pump.js:223 | Reservoir | **新增** |
| lib/plugins/pump.js:227 | URGENT: Pump Reservoir Low | **新增** |
| lib/plugins/pump.js:230 | Warning, Pump Reservoir Low | **新增** |
| lib/plugins/pump.js:234 | Insulet | **新增** |
| lib/plugins/pump.js:249 | Battery | **新增** |
| lib/plugins/pump.js:256 | URGENT: Pump Battery Low | **新增** |
| lib/plugins/pump.js:259 | Warning, Pump Battery Low | **新增** |
| lib/plugins/pump.js:272 | Pump Battery: | **新增** |
| lib/plugins/pump.js:276 | Pump Reservoir: | **新增** |
| lib/plugins/pump.js:296 | Pump Suspended | **新增** |
| lib/plugins/pump.js:331 | Pump Status | **新增** |
| lib/plugins/rawbg.js:9 | Raw BG | 已有 key |
| lib/plugins/rawbg.js:35 | Raw BG: | **新增** |
| lib/plugins/rawbg.js:123 | MetricNow | **新增** |
| lib/plugins/runtimestate.js:7 | Runtime state | **新增** |
| lib/plugins/sensorage.js:12 | Sensor Age | 已有 key |
| lib/plugins/sensorage.js:52 | Sensor Start | 已有 key |
| lib/plugins/sensorage.js:57 | Sensor Change | **新增** |
| lib/plugins/sensorage.js:167 | SAGE | 已有 key |
| lib/plugins/sensorage.js:182 | Sensor Insert | 已有 key |
| lib/plugins/simplealarms.js:9 | Simple Alarms | **新增** |
| lib/plugins/simplealarms.js:44 | HIGH | **新增** |
| lib/plugins/speech.js:13 | Speech | 已有 key |
| lib/plugins/timeago.js:13 | Timeago | **新增** |
| lib/plugins/timeago.js:45 | Time Ago | **新增** |
| lib/plugins/treatmentnotify.js:7 | BG Check | 已有 key |
| lib/plugins/treatmentnotify.js:7 | Meal Bolus | 已有 key |
| lib/plugins/treatmentnotify.js:7 | Carb Correction | 已有 key |
| lib/plugins/treatmentnotify.js:7 | Correction Bolus | 已有 key |
| lib/plugins/treatmentnotify.js:13 | Treatment Notifications | **新增** |
| lib/plugins/treatmentnotify.js:70 | Snoozing alarms since there was a recent treatment | **新增** |
| lib/plugins/treatmentnotify.js:83 | Calibration | 已有 key |
| lib/plugins/treatmentnotify.js:98 | Announcement | 已有 key |
| lib/plugins/treatmentnotify.js:113 | Temporary Target | 已有 key |
| lib/plugins/treatmentnotify.js:114 | Cancel | 已有 key |
| lib/plugins/treatmentnotify.js:127 | Note | 已有 key |
| lib/plugins/upbat.js:11 | Uploader Battery | **新增** |
| lib/plugins/upbat.js:179 | Uploader Battery is Low | **新增** |
| lib/plugins/upbat.js:210 | Voltage | **新增** |
| lib/plugins/upbat.js:213 | Temp | **新增** |
| lib/plugins/upbat.js:244 | UploaderBattery | **新增** |
| lib/plugins/upbat.js:248 | MetricNow | **新增** |
| lib/plugins/virtAsstBase.js:19 | Status | 已有 key |
| lib/plugins/virtAsstBase.js:37 | BG Status | **新增** |
| lib/plugins/virtAsstBase.js:39 | NSStatus | **新增** |
| lib/plugins/virtAsstBase.js:46 | MetricNow | **新增** |
| lib/plugins/webhook.js:69 | POST | **新增** |
| lib/plugins/webhook.js:155 | Webhook Notifier | **新增** |
| lib/plugins/xdripjs.js:15 | CGM Status | **新增** |
| lib/plugins/xdripjs.js:153 | CGM Transmitter state: | **新增** |
| lib/plugins/xdripjs.js:166 | CGM Transmitter Battery A Low Voltage: | **新增** |
| lib/plugins/xdripjs.js:167 | CGM Transmitter Battery Low | **新增** |
| lib/plugins/xdripjs.js:173 | CGM Transmitter Battery B Low Voltage: | **新增** |
| lib/plugins/xdripjs.js:220 | Unknown | **新增** |
| lib/plugins/xdripjs.js:226 | State Time: | **新增** |
| lib/plugins/xdripjs.js:237 | Session Age: | **新增** |
| lib/plugins/xdripjs.js:240 | Tx ID: | **新增** |
| lib/plugins/xdripjs.js:241 | Tx Status: | **新增** |
| lib/plugins/xdripjs.js:245 | Tx Age: | **新增** |
| lib/plugins/xdripjs.js:338 | MetricNow | **新增** |
| lib/plugins/xdripjs.js:340 | Mode | **新增** |
| lib/plugins/xdripjs.js:345 | Status | 已有 key |
| lib/plugins/xdripjs.js:376 | TxStatus | **新增** |
| lib/plugins/xdripjs.js:394 | Noise | 已有 key |
| lib/report_plugins/calibrations.js:8 | Calibrations | 已有 key |
| lib/report_plugins/calibrations.js:35 | Sensor Start | 已有 key |
| lib/report_plugins/calibrations.js:38 | Sensor Change | **新增** |
| lib/report_plugins/calibrations.js:63 | Aqua | **新增** |
| lib/report_plugins/calibrations.js:63 | Blue | **新增** |
| lib/report_plugins/calibrations.js:63 | Brown | **新增** |
| lib/report_plugins/calibrations.js:63 | Chartreuse | **新增** |
| lib/report_plugins/calibrations.js:63 | Coral | **新增** |
| lib/report_plugins/calibrations.js:63 | CornflowerBlue | **新增** |
| lib/report_plugins/calibrations.js:63 | DarkCyan | **新增** |
| lib/report_plugins/calibrations.js:63 | DarkMagenta | **新增** |
| lib/report_plugins/calibrations.js:63 | DarkOrange | **新增** |
| lib/report_plugins/calibrations.js:63 | Fuchsia | **新增** |
| lib/report_plugins/calibrations.js:63 | Green | **新增** |
| lib/report_plugins/calibrations.js:63 | Yellow | **新增** |
| lib/report_plugins/calibrations.js:70 | White | **新增** |
| lib/report_plugins/calibrations.js:79 | MBG | **新增** |
| lib/report_plugins/calibrations.js:84 | CAL | **新增** |
| lib/report_plugins/calibrations.js:145 | WhiteSmoke | **新增** |
| lib/report_plugins/dailystats.js:5 | Daily Stats | 已有 key |
| lib/report_plugins/dailystats.js:26 | border: 1px #ccc solid; | **新增** |
| lib/report_plugins/daytoday.js:10 | Day to day | 已有 key |
| lib/report_plugins/daytoday.js:42 | 800x250px | **新增** |
| lib/report_plugins/daytoday.js:43 | 1000x300px | **新增** |
| lib/report_plugins/daytoday.js:44 | 1200x400px | **新增** |
| lib/report_plugins/daytoday.js:45 | 1550x600px | **新增** |
| lib/report_plugins/daytoday.js:46 | 2400x800px | **新增** |
| lib/report_plugins/daytoday.js:59 | minutes | 已有 key |
| lib/report_plugins/daytoday.js:180 | WhiteSmoke | **新增** |
| lib/report_plugins/daytoday.js:306 | BG: | **新增** |
| lib/report_plugins/daytoday.js:308 | Meal Assist: | **新增** |
| lib/report_plugins/daytoday.js:708 | Note | 已有 key |
| lib/report_plugins/daytoday.js:730 | Temp Basal | 已有 key |
| lib/report_plugins/daytoday.js:798 | Combo Bolus | 已有 key |
| lib/report_plugins/daytoday.js:800 | Profile Switch | 已有 key |
| lib/report_plugins/daytoday.js:813 | BG Check | 已有 key |
| lib/report_plugins/daytoday.js:1010 | Exercise | 已有 key |
| lib/report_plugins/daytoday.js:1011 | Violet | **新增** |
| lib/report_plugins/daytoday.js:1013 | Salmon | **新增** |
| lib/report_plugins/daytoday.js:1014 | OpenAPS Offline | 已有 key |
| lib/report_plugins/daytoday.js:1015 | Brown | **新增** |
| lib/report_plugins/daytoday.js:1016 | Temporary Override | **新增** |
| lib/report_plugins/glucosedistribution.js:7 | Distribution | 已有 key |
| lib/report_plugins/glucosedistribution.js:36 | Diabetes care | **新增** |
| lib/report_plugins/glucosedistribution.js:87 | border: 1px #ccc solid; | **新增** |
| lib/report_plugins/glucosedistribution.js:269 | Normal | 已有 key |
| lib/report_plugins/glucosedistribution.js:269 | High | 已有 key |
| lib/report_plugins/glucosedistribution.js:315 | In Range | 已有 key |
| lib/report_plugins/glucosedistribution.js:326 | N/A | **新增** |
| lib/report_plugins/glucosedistribution.js:353 | DCCT | **新增** |
| lib/report_plugins/glucosedistribution.js:353 | IFCC | **新增** |
| lib/report_plugins/glucosedistribution.js:476 | GVI | **新增** |
| lib/report_plugins/glucosedistribution.js:476 | PGS | **新增** |
| lib/report_plugins/glucosedistribution.js:479 | Out of Range RMS | **新增** |
| lib/report_plugins/glucosedistribution.js:479 | GMI | **新增** |
| lib/report_plugins/glucosedistribution.js:479 | Revised GMI | **新增** |
| lib/report_plugins/hourlystats.js:7 | Hourly stats | 已有 key |
| lib/report_plugins/hourlystats.js:32 | #hourlystats-placeholder td { | **新增** |
| lib/report_plugins/loopalyzer.js:10 | Loopalyzer | **新增** |
| lib/report_plugins/loopalyzer.js:29 | Loopalyzer&nbsp;&nbsp; | **新增** |
| lib/report_plugins/loopalyzer.js:86 | #loopalyzer-profiles-table table { margin: 0 10px; border-collapse: collapse; border: 0px; } | **新增** |
| lib/report_plugins/loopalyzer.js:87 | #loopalyzer-profiles-table td { vertical-align: top; } | **新增** |
| lib/report_plugins/loopalyzer.js:88 | #loopalyzer-profiles-table td table { margin: 0 10px; border-collapse: collapse; border: 0px; } | **新增** |
| lib/report_plugins/loopalyzer.js:89 | #loopalyzer-profiles-table td caption { text-align: left; font-weight: bold; } | **新增** |
| lib/report_plugins/loopalyzer.js:90 | #loopalyzer-profiles-table td th { background-color: #4CAF50; color: white; } | **新增** |
| lib/report_plugins/loopalyzer.js:91 | #loopalyzer-profiles-table td td { text-align: right; vertical-align: top; padding: 0 1px; } | **新增** |
| lib/report_plugins/loopalyzer.js:92 | #loopalyzer-profiles-table td td td { padding: 1px 8px; } | **新增** |
| lib/report_plugins/loopalyzer.js:177 | getIOBs iobStatusAvailable= | **新增** |
| lib/report_plugins/loopalyzer.js:189 | getIOBs iobArray | **新增** |
| lib/report_plugins/loopalyzer.js:240 | getCOBs cobStatusAvailable= | **新增** |
| lib/report_plugins/loopalyzer.js:252 | getCOBs cobArray | **新增** |
| lib/report_plugins/loopalyzer.js:360 | Carb treatments | **新增** |
| lib/report_plugins/loopalyzer.js:375 | Insulin treatments | **新增** |
| lib/report_plugins/percentile.js:5 | Percentile Chart | 已有 key |
| lib/report_plugins/profiles.js:8 | Profiles | 已有 key |
| lib/report_plugins/profiles.js:55 | #profiles-databaserecords option | **新增** |
| lib/report_plugins/success.js:7 | Weekly Distribution | 已有 key |
| lib/report_plugins/treatments.js:9 | Treatments | 已有 key |
| lib/report_plugins/treatments.js:102 | .border_bottom td { | **新增** |
| lib/report_plugins/treatments.js:103 | border-bottom:1pt solid #eee; | **新增** |
| lib/report_plugins/treatments.js:108 | #treatments-report .filterbar select { | **新增** |
| lib/report_plugins/treatments.js:173 | DELETE | **新增** |
| lib/report_plugins/treatments.js:194 | Temp Basal | 已有 key |
| lib/report_plugins/treatments.js:251 | Sensor | 已有 key |
| lib/report_plugins/treatments.js:252 | Finger | 已有 key |
| lib/report_plugins/treatments.js:253 | Manual | 已有 key |
| lib/report_plugins/weektoweek.js:18 | Week to week | 已有 key |
| lib/report_plugins/weektoweek.js:31 | 800x250px | **新增** |
| lib/report_plugins/weektoweek.js:32 | 1000x300px | **新增** |
| lib/report_plugins/weektoweek.js:33 | 1200x400px | **新增** |
| lib/report_plugins/weektoweek.js:34 | 1550x600px | **新增** |
| lib/report_plugins/weektoweek.js:189 | WhiteSmoke | **新增** |

## P4 — 服务端文本（默认保留英文，app.js 页面标题除外）

| 位置 | 文本 | en.json |
|---|---|---|
| lib/server/aggregate.js:18 | The pipeline parameter is not supported by the Nightscout API v1. | **新增** |
| lib/server/aggregate.js:19 | Use find[...] to filter the records a count is taken over. | **新增** |
| lib/server/aggregate.js:20 | MongoQueryValidationError | **新增** |
| lib/server/app.js:219 | OPTIONS | **新增** |
| lib/server/app.js:250 | Admin Tools | 已有 key |
| lib/server/app.js:255 | Food Editor | 已有 key |
| lib/server/app.js:260 | Profile Editor | 已有 key |
| lib/server/app.js:265 | Nightscout reporting | 已有 key |
| lib/server/bootevent.js:28 | Nightscout readable by world and open to treatment entry | **新增** |
| lib/server/bootevent.js:35 | Nightscout readable by world | **新增** |
| lib/server/bootevent.js:36 | Your Nightscout installation is readable by anyone who knows the web page URL. Please consider closing access by following the Nightscout documentation: | **新增** |
| lib/server/bootevent.js:102 | ENV Error | **新增** |
| lib/server/bootevent.js:139 | Accept | **新增** |
| lib/server/bootevent.js:153 | Attempt to fetch config | **新增** |
| lib/server/bootevent.js:173 | Mandatory setting missing | **新增** |
| lib/server/bootevent.js:174 | MONGODB_URI setting is missing, cannot connect to database | **新增** |
| lib/server/bootevent.js:179 | API_SECRET setting is missing, cannot enable REST API | **新增** |
| lib/server/bootevent.js:205 | Unable to connect to Mongo | **新增** |
| lib/server/bootevent.js:225 | Unable to restore history clock | **新增** |
| lib/server/bootevent.js:243 | Unable to setup authorization | **新增** |
| lib/server/bootevent.js:368 | got data-received event, requesting reload | **新增** |
| lib/server/bootevent.js:373 | data loaded: reloading sandbox data and updating plugins | **新增** |
| lib/server/client-ip.js:32 | TRUST_PROXY must be false, true, a whole number of proxy hops (1 or more), | **新增** |
| lib/server/client-ip.js:33 | or a comma-separated list of proxy IP addresses or CIDRs | **新增** |
| lib/server/client-ip.js:47 | ; the subnet aliases | **新增** |
| lib/server/client-ip.js:50 | ; each list entry must be an IP address or CIDR, and a hop count cannot be combined with addresses | **新增** |
| lib/server/enclave.js:1 | use strict; | **新增** |
| lib/server/entries.js:108 | Entry type must be a string | **新增** |
| lib/server/env.js:42 | PORT | **新增** |
| lib/server/env.js:123 | API_SECRET should be at least | **新增** |
| lib/server/env.js:134 | Security issue | 已有 key |
| lib/server/env.js:134 | Weak API_SECRET detected. Please use a mix of small and CAPITAL letters, numbers and non-alphanumeric characters such as !#%&/ to reduce the risk of unauthorized access. The minimum length of the API_SECRET is 12 characters. | 已有 key |
| lib/server/env.js:140 | MongoDB password and API_SECRET match. This is a really bad idea. Please change both and do not reuse passwords across the system. | 已有 key |
| lib/server/env.js:154 | MONGO | **新增** |
| lib/server/env.js:271 | HOSTNAME | **新增** |
| lib/server/error-handler.js:26 | Error | 已有 key |
| lib/server/loop.js:17 | Loop notification failed: LOOP_APNS_KEY not set. | **新增** |
| lib/server/loop.js:22 | Loop notification failed: LOOP_APNS_KEY_ID not set. | **新增** |
| lib/server/loop.js:27 | Loop notification failed: LOOP_DEVELOPER_TEAM_ID not set. | **新增** |
| lib/server/loop.js:32 | Loop notification failed: Could not find loopSettings in profile. | **新增** |
| lib/server/loop.js:40 | Loop notification failed: Could not find deviceToken in loopSettings. | **新增** |
| lib/server/loop.js:45 | Loop notification failed: Could not find bundleIdentifier in loopSettings. | **新增** |
| lib/server/loop.js:67 | Temporary Override Cancel | **新增** |
| lib/server/loop.js:69 | Cancel Temporary Override | **新增** |
| lib/server/loop.js:70 | Temporary Override | **新增** |
| lib/server/loop.js:76 | Remote Carbs Entry | **新增** |
| lib/server/loop.js:89 | Remote Carbs Entry: | **新增** |
| lib/server/loop.js:90 | Absorption Time: | **新增** |
| lib/server/loop.js:92 | Loop remote carbs failed. Incorrect carbs entry: | **新增** |
| lib/server/loop.js:96 | Remote Bolus Entry | **新增** |
| lib/server/loop.js:99 | Remote Bolus Entry: | **新增** |
| lib/server/loop.js:104 | Loop remote bolus failed. Incorrect bolus entry: | **新增** |
| lib/server/loop.js:108 | Loop notification failed: Unhandled event type: | **新增** |
| lib/server/loop.js:150 | Unknown reason | **新增** |
| lib/server/loop.js:158 | APNs delivery failed: No failure details available. | **新增** |
| lib/server/loop.js:166 | Unknown error | **新增** |
| lib/server/object-id-forms.js:54 | ObjectId | **新增** |
| lib/server/object-id-forms.js:65 | \|\| (typeof doc._id !== | **新增** |
| lib/server/object-id-forms.js:83 | not an ObjectId or a 24-hex string: | **新增** |
| lib/server/pebble.js:16 | NOT COMPUTABLE | **新增** |
| lib/server/pebble.js:17 | RATE OUT OF RANGE | **新增** |
| lib/server/profile.js:267 | Profile startDate must be a string, finite number, valid date, or null | **新增** |
| lib/server/purifier.js:92 | HTML-containing text field exceeds the sanitization limit | **新增** |
| lib/server/purifier.js:154 | Object exceeds the sanitization complexity limit | **新增** |
| lib/server/query-operator-allowlist.js:82 | Query operator | **新增** |
| lib/server/query-operator-allowlist.js:82 | is not supported by the Nightscout API v1 | **新增** |
| lib/server/query-operator-allowlist.js:83 | . Supported operators: | **新增** |
| lib/server/query-operator-allowlist.js:86 | MongoQueryValidationError | **新增** |
| lib/server/query.js:107 | Cannot parse | **新增** |
| lib/server/query.js:107 | as a valid ISO-8601 date | **新增** |
| lib/server/server.js:79 | All Clear | **新增** |
| lib/server/server.js:80 | Server started without alarms | **新增** |
| lib/server/srv-dates.js:107 | ObjectId | **新增** |
| lib/server/storage-purifier.js:8 | Storage write purifier is not configured | **新增** |
| lib/server/storage-purifier.js:14 | Storage writes require document objects | **新增** |
| lib/server/treatments.js:25 | Treatment | **新增** |
| lib/server/treatments.js:535 | Announcement | 已有 key |
| lib/server/websocket.js:157 | DataReceivers | **新增** |
| lib/server/websocket.js:265 | Invalid request | **新增** |
| lib/server/websocket.js:271 | Wrong collection | **新增** |
| lib/server/websocket.js:276 | Not authorized | **新增** |
| lib/server/websocket.js:282 | Not permitted | **新增** |
| lib/server/websocket.js:299 | Invalid data | **新增** |
| lib/server/websocket.js:306 | Missing or invalid _id | **新增** |
| lib/server/websocket.js:385 | Unable to process update | **新增** |
| lib/server/websocket.js:836 | Unable to process removal | **新增** |

---

### 扫描方法与已知局限

- JS 字面量启发式匹配（≥2 个字母词/首字母大写单词），已排除 console 日志、require、translate() 已包裹项、URL/路径/纯小写标识符
- 少量误报（如 `MetricNow` 图表内部键、alexa API 字段），Phase 3-6 落地时按文件人工甄别
- 原始数据：`/tmp/i18n-scan-results.json`（含全部明细，供后续阶段机读）