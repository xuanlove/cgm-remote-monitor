<h1><?= t('dash.title') ?> <span class="hint" style="font-size:13px;">· <?= t('dash.owner', ['name' => htmlspecialchars($user['username'])]) ?></span></h1>

<div id="alertBanner" style="display:none;padding:10px 16px;border-radius:10px;margin-bottom:16px;font-weight:600;"></div>

<div class="card">
  <div style="display:flex;gap:14px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
    <span style="font-size:26px;font-weight:600;" id="latestSgv">--</span>
    <span style="font-size:20px;" id="latestDir"></span>
    <span class="hint" id="latestDelta"></span>
    <span style="flex:1;"></span>
    <span class="hint" id="liveState"><?= t('dash.live.connecting') ?></span>
    <span class="hint" id="chipIob"><?= t('dash.chip.iob', ['value' => '-']) ?></span>
    <span class="hint" id="chipCob"><?= t('dash.chip.cob', ['value' => '-']) ?></span>
    <span class="hint" id="chipBattery"><?= t('dash.chip.battery', ['value' => '-']) ?></span>
    <a href="/settings/alerts" class="hint"><?= t('dash.alertsLink') ?></a>
  </div>
  <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
    <strong><?= t('dash.window') ?></strong>
    <select id="hours" onchange="loadAll()">
      <?php foreach ([3, 6, 12, 24] as $h): ?>
        <option value="<?= $h ?>" <?= $h === 6 ? 'selected' : '' ?>><?= t('dash.lastHours', ['n' => $h]) ?></option>
      <?php endforeach; ?>
    </select>
    <strong><?= t('dash.historyDate') ?></strong>
    <input type="date" id="historyDate" onchange="loadAll()" style="width:auto;">
    <button type="button" id="backToLive" style="margin:0;padding:4px 12px;display:none;" onclick="backToLive()"><?= t('dash.backToLive') ?></button>
    <label style="display:inline-flex;align-items:center;gap:4px;margin:0;"><input type="checkbox" id="smooth" onchange="loadAll()" style="width:auto;"> <?= t('dash.smooth') ?></label>
    <span id="updated" class="hint"></span>
    <span id="chartMsg" class="msg-bad" style="display:none;"></span>
  </div>
  <div id="chart" style="width:100%;height:420px;"></div>
  <p class="hint"><?= t('dash.reference') ?></p>
</div>

<div class="card">
  <h1 style="font-size:16px;"><?= t('dash.treatmentsTitle') ?></h1>
  <table id="treatments">
    <thead><tr>
      <th><?= t('col.time') ?></th>
      <th><?= t('col.type') ?></th>
      <th><?= t('col.glucose') ?></th>
      <th><?= t('col.carbs') ?></th>
      <th><?= t('col.insulin') ?></th>
      <th><?= t('col.notes') ?></th>
      <th></th>
    </tr></thead>
    <tbody></tbody>
  </table>
</div>

<script src="/vendor/echarts.min.js"></script>
<script>
const I18N = <?= \NightPanel\Lang::js([
    'dash.updatedAt',
    'dash.loadFailed',
    'dash.empty',
    'dash.reference',
    'dash.legend.glucose',
    'dash.legend.mbg',
    'dash.targetBand',
    'dash.chip.iob',
    'dash.chip.cob',
    'dash.chip.battery',
    'dash.live.connecting',
    'dash.live.live',
    'dash.live.retry',
    'dash.live.error',
    'dash.live.polling',
    'status.low',
    'status.high',
    'status.veryHigh',
    'status.inRange',
    'alert.lowBanner',
    'alert.highBanner',
    'alert.lowTitle',
    'alert.highTitle',
    'alert.body',
    'col.edit',
    'col.time',
]) ?>;
const LOCALE = <?= json_encode(\NightPanel\Lang::htmlLang()) ?>;

/* mg/dL → mmol/L */
const MGDL_TO_MMOL = 18;

/* Nightscout direction → 趋势箭头 */
const DIR_ARROWS = {
  DoubleUp: '↑↑', SingleUp: '↑', FortyFiveUp: '↗', Flat: '→',
  FortyFiveDown: '↘', SingleDown: '↓', DoubleDown: '↓↓',
  'NOT COMPUTABLE': '⇄', 'RATE OUT OF RANGE': '⇄', error: '?'
};

function themeColors() {
  const light = document.documentElement.classList.contains('light');
  return light
    ? { fg: '#1c2430', muted: '#5f6b7e', line: '#d8dee9', accent: '#2f6fe0', band: 'rgba(34,136,94,.12)', card: '#ffffff', mbg: '#5f6b7e' }
    : { fg: '#e6ebf4', muted: '#8b95a9', line: '#313a4d', accent: '#4f8ef7', band: 'rgba(63,178,127,.12)', card: '#232a38', mbg: '#8b95a9' };
}

function statusOf (v, low, high) {
  if (v < low)  return { label: I18N['status.low'], color: '#e05a5a' };
  if (v > 10)   return { label: I18N['status.veryHigh'], color: '#e05a5a' };
  if (v > high) return { label: I18N['status.high'], color: '#e0a05a' };
  return        { label: I18N['status.inRange'], color: '#3fb27f' };
}

function fmtDelta (deltaMmol) {
  if (deltaMmol === null || isNaN(deltaMmol)) return null;
  return (deltaMmol >= 0 ? '▲ +' : '▼ ') + deltaMmol.toFixed(1) + ' mmol/L';
}

function smoothSeries (points) {
  /* Nightscout 式 3 点均值降噪 */
  return points.map((p, i) => {
    if (i === 0 || i === points.length - 1) return { ...p, v: p.v };
    const a = points[i - 1].v, b = p.v, c = points[i + 1].v;
    return { ...p, v: +( (a + b + c) / 3 ).toFixed(2) };
  });
}

let chart = null;

async function loadAll() {
  const hours = document.getElementById('hours').value;
  const dateVal = document.getElementById('historyDate').value;
  const smooth = document.getElementById('smooth').checked;
  document.getElementById('backToLive').style.display = dateVal ? 'inline-block' : 'none';

  const url = dateVal ? '/data/glucose.json?date=' + dateVal : '/data/glucose.json';
  let glucose, treatments;
  try {
    const [g, t] = await Promise.all([
      fetch(url).then(r => r.json()),
      fetch('/data/treatments.json').then(r => r.json())
    ]);
    glucose = g; treatments = t;
    window.__glucosePayload = glucose;
    document.getElementById('chartMsg').style.display = 'none';
  } catch (e) {
    const msg = document.getElementById('chartMsg');
    msg.textContent = I18N['dash.loadFailed'].replace(':msg', e.message);
    msg.style.display = 'inline';
    return;
  }

  /* 实时模式按时间窗过滤；历史日模式整天展示 */
  const cutoff = dateVal ? 0 : Date.now() - hours * 3600 * 1000;
  const entries = (glucose.entries || [])
    .map(e => ({ ts: e.mills || (e.date ? +e.date : Date.parse(e.dateString)), v: +(e.sgv / MGDL_TO_MMOL).toFixed(2), dir: e.direction || null, d: e.delta }))
    .filter(p => p.ts && p.ts >= cutoff)
    .sort((a, b) => a.ts - b.ts);

  const shown = smooth && entries.length > 2 ? smoothSeries(entries) : entries;

  /* 状态条：最新值 + 趋势箭头 + delta（entry 缺 delta 时用相邻差值兜底） */
  if (entries.length > 0) {
    const last = entries[entries.length - 1];
    const prev = entries.length > 1 ? entries[entries.length - 2] : null;
    const deltaMmol = last.d != null ? last.d / MGDL_TO_MMOL : (prev ? +(last.v - prev.v).toFixed(2) : null);
    document.getElementById('latestSgv').textContent = last.v.toFixed(1);
    document.getElementById('latestSgv').style.color = statusOf(last.v, targetLowOf(glucose), targetHighOf(glucose)).color;
    document.getElementById('latestDir').textContent = DIR_ARROWS[last.dir] || '';
    document.getElementById('latestDelta').textContent = fmtDelta(deltaMmol) || '';
    evaluateAlerts(last.v, glucose.alerts);
  } else {
    document.getElementById('latestSgv').textContent = '--';
    document.getElementById('latestDir').textContent = '';
    document.getElementById('latestDelta').textContent = '';
  }

  /* 设备状态 chips：IOB / COB / 上传器电量（缺字段显示 -） */
  const ds = glucose.devicestatus || {};
  const iob = ds.iob && ds.iob.iob != null ? ds.iob.iob + ' U' : '-';
  const cob = ds.cob && ds.cob.cob != null ? ds.cob.cob + ' g' : '-';
  const battery = (ds.uploader && ds.uploader.battery != null) ? ds.uploader.battery + '%' : '-';
  document.getElementById('chipIob').textContent = I18N['dash.chip.iob'].replace(':value', iob);
  document.getElementById('chipCob').textContent = I18N['dash.chip.cob'].replace(':value', cob);
  document.getElementById('chipBattery').textContent = I18N['dash.chip.battery'].replace(':value', battery);

  /* 目标范围：来自登录用户自己的 profile 文档（mg/dL → mmol/L），缺省 3.9/6.1 */
  const targetLow  = targetLowOf(glucose);
  const targetHigh = targetHighOf(glucose);

  /* 校准血糖 mbg：空心方块叠加 */
  const mbgPoints = (glucose.mbg || [])
    .map(e => ({ ts: e.mills || (e.date ? +e.date : Date.parse(e.dateString)), v: +(e.mbg / MGDL_TO_MMOL).toFixed(2) }))
    .filter(p => p.ts && p.ts >= cutoff)
    .sort((a, b) => a.ts - b.ts)
    .map(p => [p.ts, p.v]);

  buildChart(shown, mbgPoints, targetLow, targetHigh);
  document.getElementById('updated').textContent = I18N['dash.updatedAt']
    .replace(':time', new Date().toLocaleTimeString(LOCALE))
    .replace(':count', shown.length)
    .replace(':day', dateVal ? ' · ' + dateVal : '');

  const tbody = document.querySelector('#treatments tbody');
  tbody.innerHTML = '';
  (treatments.treatments || []).sort((a, b) => new Date(b.created_at) - new Date(a.created_at)).forEach(t => {
    const row = document.createElement('tr');
    row.innerHTML = '<td>' + new Date(t.created_at).toLocaleString(LOCALE) + '</td>'
      + '<td>' + (t.eventType || '-') + '</td>'
      + '<td>' + (t.glucose ?? '-') + '</td>'
      + '<td>' + (t.carbs ?? '-') + '</td>'
      + '<td>' + (t.insulin ?? '-') + '</td>'
      + '<td>' + (t.notes || '') + '</td>'
      + '<td><a href="/treatments?edit=' + encodeURIComponent(t._id) + '">' + I18N['col.edit'] + '</a></td>';
    tbody.appendChild(row);
  });
}

function targetLowOf (glucose) {
  const arr = glucose.profile && glucose.profile.target_low;
  return (arr && arr.length) ? arr[arr.length - 1][1] / MGDL_TO_MMOL : 3.9;
}
function targetHighOf (glucose) {
  const arr = glucose.profile && glucose.profile.target_high;
  return (arr && arr.length) ? arr[arr.length - 1][1] / MGDL_TO_MMOL : 6.1;
}

function buildChart(entries, mbgPoints, targetLow, targetHigh) {
  const c = themeColors();
  const el = document.getElementById('chart');

  if (entries.length === 0 && mbgPoints.length === 0) {
    if (chart) { chart.dispose(); chart = null; }
    el.innerHTML = '<p class="hint" style="padding:40px;text-align:center;">' + I18N['dash.empty'] + '</p>';
    return;
  }
  // Clear the "no data" placeholder BEFORE the instance is created: wiping the
  // container afterwards would remove the canvas echarts just mounted.
  if (!chart) {
    el.innerHTML = '';
    chart = echarts.init(el);
  }

  const maxValue = Math.max(
    ...entries.map(p => p.v), ...mbgPoints.map(p => p[1]), targetHigh
  );

  const deltaFormatter = idx => {
    const e = entries[idx];
    if (!e) return '';
    const deltaMmol = e.d != null ? e.d / MGDL_TO_MMOL : (idx > 0 ? e.v - entries[idx - 1].v : null);
    const d = fmtDelta(deltaMmol);
    return d ? '<br/>' + d : '';
  };

  const series = [
    {
      name: I18N['dash.legend.glucose'],
      type: 'line',
      data: entries.map(p => [p.ts, p.v]),
      lineStyle: { color: c.accent, width: 2.5 },
      showSymbol: true,
      symbol: 'circle',
      symbolSize: 8,
      itemStyle: {
        color: p => statusOf(p.value[1], targetLow, targetHigh).color,
        borderColor: c.card, borderWidth: 1
      },
      emphasis: { scale: 1.6, itemStyle: { shadowBlur: 10, shadowColor: 'rgba(79,142,247,.6)' } },
      areaStyle: {
        color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
          { offset: 0, color: hexToRgba(c.accent, .30) },
          { offset: 1, color: hexToRgba(c.accent, 0) }
        ])
      },
      markArea: {
        silent: true,
        itemStyle: { color: c.band },
        label: { show: true, position: 'insideTopRight', color: '#3fb27f', fontSize: 11 },
        data: [[{ name: I18N['dash.targetBand'], yAxis: targetLow }, { yAxis: targetHigh }]]
      },
      markLine: {
        silent: true,
        symbol: 'none',
        lineStyle: { type: 'dashed' },
        label: { formatter: p => p.name, fontSize: 11 },
        data: [
          { yAxis: targetLow,  name: targetLow.toFixed(1),  lineStyle: { color: '#e05a5a' }, label: { color: '#e05a5a', position: 'insideStartTop' } },
          { yAxis: targetHigh, name: targetHigh.toFixed(1), lineStyle: { color: '#e0a05a' }, label: { color: '#e0a05a', position: 'insideStartBottom' } }
        ]
      }
    }
  ];

  if (mbgPoints.length > 0) {
    series.push({
      name: I18N['dash.legend.mbg'],
      type: 'scatter',
      data: mbgPoints,
      symbol: 'rect',
      symbolSize: 10,
      itemStyle: { color: 'transparent', borderColor: c.mbg, borderWidth: 2 },
      emphasis: { scale: 1.4 }
    });
  }

  chart.setOption({
    backgroundColor: 'transparent',
    textStyle: { color: c.muted },
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'line', lineStyle: { color: c.muted, type: 'dashed' } },
      formatter: params => {
        const p = params[0];
        const v = p.value[1];
        const st = statusOf(v, targetLow, targetHigh);
        const idx = p.dataIndex;
        const dir = entries[idx] && entries[idx].dir ? ' ' + (DIR_ARROWS[entries[idx].dir] || '') : '';
        const tip = I18N['dash.tooltip']
          .replace(':value', '<b style="color:' + st.color + '">' + v.toFixed(1) + '</b>')
          .replace(':status', st.label);
        return '<b>' + new Date(p.value[0]).toLocaleString(LOCALE) + '</b>' + dir
          + '<br/>' + tip + deltaFormatter(idx);
      }
    },
    legend: {
      top: 6, data: mbgPoints.length > 0
        ? [I18N['dash.legend.glucose'], I18N['dash.legend.mbg']]
        : [I18N['dash.legend.glucose']],
      textStyle: { color: c.fg }
    },
    grid: { left: 52, right: 20, top: 46, bottom: 70, containLabel: false },
    xAxis: {
      type: 'time',
      name: I18N['col.time'],
      nameTextStyle: { color: c.muted },
      axisLine: { lineStyle: { color: c.line } },
      axisTick: { show: false },
      axisLabel: { color: c.muted, hideOverlap: true }
    },
    yAxis: {
      type: 'value',
      name: 'mmol/L',
      nameTextStyle: { color: c.muted },
      min: 0,
      max: Math.ceil((maxValue + 2) / 3) * 3,
      axisLine: { show: false },
      axisLabel: { color: c.muted },
      splitLine: { lineStyle: { color: c.line, opacity: .45 } }
    },
    dataZoom: [
      { type: 'inside', start: 0, end: 100 },
      { type: 'slider', height: 20, bottom: 10, borderColor: c.line }
    ],
    series
  }, true);
}

function hexToRgba (hex, alpha) {
  const n = parseInt(hex.slice(1), 16);
  return 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + alpha + ')';
}

function backToLive () {
  document.getElementById('historyDate').value = '';
  loadAll();
}

/* ============================================================
 * D2 个人提醒线：越线横幅 + 浏览器通知（同一区间只提醒一次）
 * ============================================================ */
let alertState = null;   // null | 'low' | 'high'

function evaluateAlerts (value, settings) {
  const banner = document.getElementById('alertBanner');
  if (!settings || !settings.enabled) {
    banner.style.display = 'none';
    alertState = null;
    return;
  }
  const state = value < settings.low ? 'low' : (value > settings.high ? 'high' : null);
  if (state === null) {
    banner.style.display = 'none';
    alertState = null;
    return;
  }

  const isLow = state === 'low';
  banner.style.display = 'block';
  banner.style.background = isLow ? 'rgba(224,90,90,.16)' : 'rgba(224,160,90,.16)';
  banner.style.color = isLow ? '#e05a5a' : '#e0a05a';
  banner.style.border = '1px solid ' + (isLow ? 'rgba(224,90,90,.5)' : 'rgba(224,160,90,.5)');
  banner.textContent = (isLow ? I18N['alert.lowBanner'] : I18N['alert.highBanner'])
    .replace(':value', value.toFixed(1))
    .replace(':limit', isLow ? settings.low : settings.high);

  // Only notify on a fresh crossing, not on every refresh inside the same zone.
  if (alertState !== state) {
    alertState = state;
    maybeNotify(
      isLow ? I18N['alert.lowTitle'] : I18N['alert.highTitle'],
      I18N['alert.body']
        .replace(':value', value.toFixed(1))
        .replace(':limit', isLow ? settings.low : settings.high),
      settings
    );
  }
}

function maybeNotify (title, body, settings) {
  if (!settings || !settings.browser) return;
  if (typeof Notification === 'undefined' || Notification.permission !== 'granted') return;
  try {
    new Notification(title, { body: body, tag: 'night-panel-alert' });
  } catch (e) { /* notification failures must never break the page */ }
}

/* ============================================================
 * D1 实时推送：SSE 有新读数时更新状态条并追加数据点
 * ============================================================ */
let lastStreamTs = 0;

function setLiveState (text, color) {
  const el = document.getElementById('liveState');
  el.textContent = text;
  el.style.color = color;
}

function applyStreamReading (payload) {
  if (!payload || !payload.ts || payload.ts <= lastStreamTs) return;
  lastStreamTs = payload.ts;

  document.getElementById('latestSgv').textContent = payload.v.toFixed(1);
  document.getElementById('latestDir').textContent = DIR_ARROWS[payload.direction] || '';
  document.getElementById('latestDelta').textContent =
    fmtDelta(payload.delta != null ? payload.delta / MGDL_TO_MMOL : null) || '';

  const g = window.__glucosePayload;
  if (g) evaluateAlerts(payload.v, g.alerts);

  // A new point arrived: re-render the chart rather than patching it, so the
  // smoothing toggle and the target band stay consistent.
  if (!document.getElementById('historyDate').value) loadAll();
}

function connectStream () {
  if (typeof EventSource === 'undefined') {
    setLiveState(I18N['dash.live.polling'], '#8b95a9');
    return;
  }
  const source = new EventSource('/stream');

  source.addEventListener('open', () => setLiveState(I18N['dash.live.live'], '#3fb27f'));
  source.addEventListener('reading', ev => {
    try { applyStreamReading(JSON.parse(ev.data)); } catch (e) { }
  });
  source.addEventListener('streamerror', () => {
    setLiveState(I18N['dash.live.error'], '#e05a5a');
  });
  source.addEventListener('bye', () => {
    // The server closes the stream after ~2 minutes; reconnect deliberately so
    // the interval is explicit instead of relying on the browser's backoff.
    source.close();
    setLiveState(I18N['dash.live.retry'], '#e0a05a');
    setTimeout(connectStream, 1500);
  });
  source.addEventListener('error', () => {
    setLiveState(I18N['dash.live.retry'], '#e0a05a');
  });
}

window.addEventListener('resize', () => chart && chart.resize());

/* 跟随日间/夜间切换重建图表配色；历史日回看模式暂停自动刷新 */
new MutationObserver(() => loadAll())
  .observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

/* Slow safety net: the stream carries new readings, this only refreshes the
 * treatments table and the profile-derived band. */
setInterval(() => { if (!document.getElementById('historyDate').value) loadAll(); }, 5 * 60 * 1000);

loadAll();
connectStream();
</script>
