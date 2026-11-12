<h1>血糖仪表盘 <span class="hint" style="font-size:13px;">· 归属：device = <?= htmlspecialchars($user['username']) ?></span></h1>

<div class="card">
  <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px;">
    <strong>时间窗：</strong>
    <select id="hours" onchange="loadAll()">
      <option value="3">最近 3 小时</option>
      <option value="6" selected>最近 6 小时</option>
      <option value="12">最近 12 小时</option>
      <option value="24">最近 24 小时</option>
    </select>
    <span id="updated" class="hint"></span>
  </div>
  <canvas id="chart" height="110"></canvas>
</div>

<div class="card">
  <h1 style="font-size:16px;">我的治疗记录（最近 100 条）</h1>
  <table id="treatments">
    <thead><tr><th>时间</th><th>类型</th><th>血糖</th><th>碳水</th><th>胰岛素</th><th>备注</th></tr></thead>
    <tbody></tbody>
  </table>
</div>

<script src="/vendor/chart.umd.js"></script>
<script>
let chart = null;

async function loadAll() {
  const hours = document.getElementById('hours').value;
  const [glucose, treatments] = await Promise.all([
    fetch('/data/glucose.json').then(r => r.json()),
    fetch('/data/treatments.json').then(r => r.json())
  ]);

  const cutoff = Date.now() - hours * 3600 * 1000;
  const entries = (glucose.entries || [])
    .filter(e => (e.mills || e.date) >= cutoff)
    .sort((a, b) => (a.mills || a.date) - (b.mills || b.date));

  const points = entries.map(e => ({ x: e.mills || e.date, y: e.sgv }));
  document.getElementById('updated').textContent =
    '更新于 ' + new Date().toLocaleTimeString('zh-CN') + '（' + points.length + ' 条）';

  const datasets = [{
    label: '血糖 (mg/dL)',
    data: points,
    borderColor: '#4f8ef7',
    backgroundColor: 'rgba(79,142,247,.15)',
    fill: true,
    pointRadius: 2,
    tension: .25
  }];

  // Target band comes from the user's own profile store entry.
  const prof = glucose.profile;
  const annotations = [];
  if (prof && prof.target_low && prof.target_high) {
    const low = prof.target_low[prof.target_low.length - 1][1];
    const high = prof.target_high[prof.target_high.length - 1][1];
    annotations.push({ y: low, label: '目标下限' }, { y: high, label: '目标上限' });
    datasets.push({
      label: '目标区间',
      data: [{ x: cutoff, y: low }, { x: Date.now(), y: low }],
      borderColor: '#3fb27f',
      borderDash: [6, 6],
      pointRadius: 0,
      fill: false
    });
    datasets.push({
      data: [{ x: cutoff, y: high }, { x: Date.now(), y: high }],
      borderColor: '#e0a05a',
      borderDash: [6, 6],
      pointRadius: 0,
      fill: false
    });
  }

  const ctx = document.getElementById('chart').getContext('2d');
  if (chart) chart.destroy();
  chart = new Chart(ctx, {
    type: 'line',
    data: { datasets },
    options: {
      parsing: false,
      scales: {
        x: { type: 'linear', ticks: { callback: v => new Date(v).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' }) } },
        y: { suggestedMin: 40, suggestedMax: 300 }
      },
      plugins: {
        tooltip: { callbacks: { title: items => new Date(items[0].parsed.x).toLocaleString('zh-CN') } },
        legend: { labels: { filter: item => !item.text.startsWith('dataset-') } }
      }
    }
  });

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

loadAll();
setInterval(loadAll, 60 * 1000);
</script>
