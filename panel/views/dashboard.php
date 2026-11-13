<h1>血糖趋势 <span class="hint" style="font-size:13px;">· 归属：device = <?= htmlspecialchars($user['username']) ?> · 来自你的 Profile 档案</span></h1>

<div class="card">
  <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
    <strong>时间窗：</strong>
    <select id="hours" onchange="loadAll()">
      <option value="3">最近 3 小时</option>
      <option value="6" selected>最近 6 小时</option>
      <option value="12">最近 12 小时</option>
      <option value="24">最近 24 小时</option>
    </select>
    <span id="updated" class="hint"></span>
    <span id="chartMsg" class="msg-bad" style="display:none;"></span>
  </div>
  <div id="chart" style="width:100%;height:420px;"></div>
  <p class="hint">参考线：3.9 / 6.1 mmol/L（空腹默认）；若你在「我的 Profile」中设置了目标范围，则以你自己的档案为准。拖动缩放或使用下方滑块查看细节。</p>
</div>

<div class="card">
  <h1 style="font-size:16px;">我的治疗记录（最近 100 条）</h1>
  <table id="treatments">
    <thead><tr><th>时间</th><th>类型</th><th>血糖</th><th>碳水</th><th>胰岛素</th><th>备注</th></tr></thead>
    <tbody></tbody>
  </table>
</div>

<script src="/vendor/echarts.min.js"></script>
<script>
/* mg/dL → mmol/L */
const MGDL_TO_MMOL = 18;

/* 主题自适应配色：跟随导航栏的日间/夜间切换 */
function themeColors() {
  const light = document.documentElement.classList.contains('light');
  return light
    ? { fg: '#1c2430', muted: '#5f6b7e', line: '#d8dee9', accent: '#2f6fe0', band: 'rgba(34,136,94,.12)', card: '#ffffff' }
    : { fg: '#e6ebf4', muted: '#8b95a9', line: '#313a4d', accent: '#4f8ef7', band: 'rgba(63,178,127,.12)', card: '#232a38' };
}

function statusOf (v, low, high) {
  if (v < low)  return { label: '偏低', color: '#e05a5a' };
  if (v > 10)   return { label: '急高', color: '#e05a5a' };
  if (v > high) return { label: '偏高', color: '#e0a05a' };
  return        { label: '正常', color: '#3fb27f' };
}

let chart = null;

async function loadAll() {
  const hours = document.getElementById('hours').value;
  let glucose, treatments;
  try {
    const [g, t] = await Promise.all([
      fetch('/data/glucose.json').then(r => r.json()),
      fetch('/data/treatments.json').then(r => r.json())
    ]);
    glucose = g; treatments = t;
    document.getElementById('chartMsg').style.display = 'none';
  } catch (e) {
    const msg = document.getElementById('chartMsg');
    msg.textContent = '数据加载失败：' + e.message;
    msg.style.display = 'inline';
    return;
  }

  const cutoff = Date.now() - hours * 3600 * 1000;
  /* entries: sgv 为 mg/dL → 换算 mmol/L，时间点取 mills/date */
  const entries = (glucose.entries || [])
    .map(e => ({ ts: e.mills || (e.date ? +e.date : Date.parse(e.dateString)), v: +(e.sgv / MGDL_TO_MMOL).toFixed(2) }))
    .filter(p => p.ts && p.ts >= cutoff)
    .sort((a, b) => a.ts - b.ts);

  /* 目标范围：来自登录用户自己的 profile 文档（mg/dL → mmol/L），缺省 3.9/6.1 */
  const prof = glucose.profile || {};
  const lastPair = arr => (arr && arr.length) ? arr[arr.length - 1][1] / MGDL_TO_MMOL : null;
  const targetLow  = lastPair(prof.target_low)  || 3.9;
  const targetHigh = lastPair(prof.target_high) || 6.1;

  buildChart(entries, targetLow, targetHigh);
  document.getElementById('updated').textContent =
    '更新于 ' + new Date().toLocaleTimeString('zh-CN') + '（' + entries.length + ' 条）';

  const tbody = document.querySelector('#treatments tbody');
  tbody.innerHTML = '';
  (treatments.treatments || []).sort((a, b) => new Date(b.created_at) - new Date(a.created_at)).forEach(t => {
    const row = document.createElement('tr');
    row.innerHTML = '<td>' + new Date(t.created_at).toLocaleString('zh-CN') + '</td>'
      + '<td>' + (t.eventType || '-') + '</td>'
      + '<td>' + (t.glucose ?? '-') + '</td>'
      + '<td>' + (t.carbs ?? '-') + '</td>'
      + '<td>' + (t.insulin ?? '-') + '</td>'
      + '<td>' + (t.notes || '') + '</td>';
    tbody.appendChild(row);
  });
}

function buildChart(entries, targetLow, targetHigh) {
  const c = themeColors();
  const el = document.getElementById('chart');

  if (entries.length === 0) {
    if (chart) { chart.dispose(); chart = null; }
    el.innerHTML = '<p class="hint" style="padding:40px;text-align:center;">暂无血糖数据 —— 在「填写数据」提交血糖，或将 CGM 上传器的设备名设为你的用户名。</p>';
    return;
  }
  if (!chart) chart = echarts.init(el);
  el.innerHTML = '';

  const maxValue = Math.max(...entries.map(p => p.v), targetHigh);

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
        return '<b>' + new Date(p.value[0]).toLocaleString('zh-CN') + '</b><br/>血糖：<b style="color:' + st.color + '">'
          + v.toFixed(1) + ' mmol/L</b>（' + st.label + '）';
      }
    },
    legend: { top: 6, data: ['血糖值'], textStyle: { color: c.fg } },
    grid: { left: 52, right: 20, top: 46, bottom: 70, containLabel: false },
    xAxis: {
      type: 'time',
      name: '时间',
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
    series: [{
      name: '血糖值',
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
        data: [[{ name: '目标区间', yAxis: targetLow }, { yAxis: targetHigh }]]
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
    }]
  }, true);
}

function hexToRgba (hex, alpha) {
  const n = parseInt(hex.slice(1), 16);
  return 'rgba(' + (n >> 16 & 255) + ',' + (n >> 8 & 255) + ',' + (n & 255) + ',' + alpha + ')';
}

window.addEventListener('resize', () => chart && chart.resize());

/* 跟随日间/夜间切换重建图表配色 */
new MutationObserver(() => { if (chart) loadAll(); })
  .observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

loadAll();
setInterval(loadAll, 60 * 1000);
</script>
