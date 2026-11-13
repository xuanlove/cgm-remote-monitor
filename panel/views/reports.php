<?php
/** @var array $stats */
$pct = $stats['percent'];
$hasData = ($stats['count'] ?? 0) > 0;
?>
<h1>统计报告 <span class="hint" style="font-size:13px;">· device = <?= htmlspecialchars($user['username']) ?> · <?= htmlspecialchars($from) ?> ~ <?= htmlspecialchars($to) ?></span></h1>

<div class="card">
  <form method="get" action="/reports" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <strong>统计区间：</strong>
    <?php foreach ([7, 14, 30, 90] as $option): ?>
      <a href="/reports?days=<?= $option ?>"
         style="padding:4px 12px;border-radius:6px;text-decoration:none;<?= $days === $option ? 'background:var(--accent);color:#fff;' : 'border:1px solid var(--line);' ?>">
        近 <?= $option ?> 天
      </a>
    <?php endforeach; ?>
    <span class="hint">共 <?= (int)$stats['count'] ?> 条读数<?php if ($stats['coverage'] !== null): ?> · 数据覆盖 <?= $stats['coverage'] ?>%<?php endif; ?></span>
  </form>
</div>

<?php if (!$hasData): ?>
  <div class="card"><p class="hint">所选区间内没有血糖数据。请先在「填写数据」录入，或确认 CGM 上传器的设备名是 <?= htmlspecialchars($user['username']) ?>。</p></div>
<?php else: ?>

<div class="card">
  <div class="grid" style="grid-template-columns:repeat(4,1fr);gap:14px;">
    <div>
      <div class="hint">目标范围内 TIR（3.9–10.0）</div>
      <div style="font-size:26px;font-weight:600;color:#3fb27f;"><?= $stats['tir'] ?>%</div>
    </div>
    <div>
      <div class="hint">平均血糖</div>
      <div style="font-size:26px;font-weight:600;"><?= $stats['mean'] ?> <span style="font-size:14px;" class="hint">mmol/L</span></div>
    </div>
    <div>
      <div class="hint">GMI（预估糖化）</div>
      <div style="font-size:26px;font-weight:600;"><?= $stats['gmi'] ?? '-' ?><span style="font-size:14px;" class="hint">%</span></div>
    </div>
    <div>
      <div class="hint">变异系数 CV</div>
      <div style="font-size:26px;font-weight:600;"><?= $stats['cv'] ?? '-' ?><span style="font-size:14px;" class="hint">%</span></div>
    </div>
  </div>
  <div class="grid" style="grid-template-columns:repeat(4,1fr);gap:14px;margin-top:16px;">
    <div>
      <div class="hint">低于范围 TBR</div>
      <div style="font-size:18px;color:#e05a5a;"><?= $stats['tbr'] ?>%</div>
      <div class="hint">其中 &lt;3.0：<?= $stats['tbr2'] ?>%</div>
    </div>
    <div>
      <div class="hint">高于范围 TAR</div>
      <div style="font-size:18px;color:#e0a05a;"><?= $stats['tar'] ?>%</div>
      <div class="hint">其中 &gt;13.9：<?= $stats['tar2'] ?>%</div>
    </div>
    <div>
      <div class="hint">低血糖事件（≥15 分钟）</div>
      <div style="font-size:18px;"><?= $stats['lowEvents']['events'] ?> 次</div>
      <div class="hint">累计 <?= $stats['lowEvents']['minutes'] ?> 分钟 · 最长 <?= $stats['lowEvents']['longest'] ?> 分钟</div>
    </div>
    <div>
      <div class="hint">落在你的目标范围</div>
      <div style="font-size:18px;"><?= $stats['target']['percent'] ?>%</div>
      <div class="hint"><?= $stats['target']['low'] ?> ~ <?= $stats['target']['high'] ?> mmol/L（你的 Profile）</div>
    </div>
  </div>
</div>

<div class="card">
  <h1 style="font-size:16px;">血糖分布</h1>
  <div id="distribution" style="width:100%;height:320px;"></div>
</div>

<div class="card">
  <h1 style="font-size:16px;">时间占比（TIR / TAR / TBR）</h1>
  <div id="tirChart" style="width:100%;height:320px;"></div>
  <p class="hint">参考阈值：&lt;3.0 极低 · 3.0–3.9 偏低 · 3.9–10.0 目标范围 · 10.0–13.9 偏高 · &gt;13.9 极高。中位数 <?= $stats['median'] ?>、标准差 <?= $stats['std'] ?>、最低 <?= $stats['min'] ?>、最高 <?= $stats['max'] ?> mmol/L。</p>
</div>

<script src="/vendor/echarts.min.js"></script>
<script>
const STATS = <?= json_encode($stats, JSON_UNESCAPED_UNICODE) ?>;

function themeColors () {
  const light = document.documentElement.classList.contains('light');
  return light
    ? { fg: '#1c2430', muted: '#5f6b7e', line: '#d8dee9' }
    : { fg: '#e6ebf4', muted: '#8b95a9', line: '#313a4d' };
}

const BUCKETS = [
  { key: 'veryLow',  label: '<3.0 极低',      color: '#b53a3a' },
  { key: 'low',      label: '3.0–3.9 偏低',   color: '#e05a5a' },
  { key: 'inRange',  label: '3.9–10.0 目标',  color: '#3fb27f' },
  { key: 'high',     label: '10.0–13.9 偏高', color: '#e0a05a' },
  { key: 'veryHigh', label: '>13.9 极高',     color: '#c07d2a' }
];

const distribution = echarts.init(document.getElementById('distribution'));
const tirChart = echarts.init(document.getElementById('tirChart'));
window.addEventListener('resize', () => { distribution.resize(); tirChart.resize(); });

function render () {
  const c = themeColors();
  const base = {
    backgroundColor: 'transparent',
    textStyle: { color: c.muted },
    grid: { left: 56, right: 24, top: 30, bottom: 40 }
  };

  distribution.setOption(Object.assign({}, base, {
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'shadow' },
      formatter: p => {
        const i = p[0].dataIndex;
        return '<b>' + BUCKETS[i].label + '</b><br/>占比 ' + STATS.percent[BUCKETS[i].key] + '%<br/>读数 ' + STATS.buckets[BUCKETS[i].key] + ' 条';
      }
    },
    xAxis: {
      type: 'category',
      data: BUCKETS.map(b => b.label),
      axisLine: { lineStyle: { color: c.line } },
      axisTick: { show: false },
      axisLabel: { color: c.muted, interval: 0, fontSize: 11 }
    },
    yAxis: {
      type: 'value',
      name: '%',
      max: 100,
      axisLine: { show: false },
      axisLabel: { color: c.muted },
      splitLine: { lineStyle: { color: c.line, opacity: .45 } }
    },
    series: [{
      type: 'bar',
      barMaxWidth: 64,
      data: BUCKETS.map(b => ({ value: STATS.percent[b.key], itemStyle: { color: b.color, borderRadius: [4, 4, 0, 0] } })),
      label: { show: true, position: 'top', formatter: '{c}%', color: c.fg, fontSize: 12 }
    }]
  }), true);

  const segments = [
    { name: '低于范围', value: STATS.tbr, color: '#e05a5a' },
    { name: '目标范围', value: STATS.tir, color: '#3fb27f' },
    { name: '高于范围', value: STATS.tar, color: '#e0a05a' }
  ];

  tirChart.setOption({
    backgroundColor: 'transparent',
    textStyle: { color: c.muted },
    tooltip: { trigger: 'item', formatter: '{b}: {c}%' },
    legend: { bottom: 0, textStyle: { color: c.fg } },
    series: [{
      type: 'pie',
      radius: ['48%', '72%'],
      center: ['50%', '45%'],
      avoidLabelOverlap: true,
      itemStyle: { borderColor: 'transparent', borderWidth: 2 },
      label: { show: true, formatter: '{b}\n{c}%', color: c.fg, fontSize: 12 },
      data: segments.map(s => ({ name: s.name, value: s.value, itemStyle: { color: s.color } }))
    }]
  }, true);
}

render();
new MutationObserver(render).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
</script>

<?php endif; ?>
