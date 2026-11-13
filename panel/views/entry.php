<h1>填写数据</h1>

<?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if (!empty($success)): ?><p class="msg-ok"><?= htmlspecialchars($success) ?></p><?php endif; ?>

<div class="card">
  <h1 style="font-size:16px;">手填血糖</h1>
  <form method="post" action="/entry">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
    <input type="hidden" name="kind" value="glucose">
    <div class="grid">
      <div><label>血糖值 mg/dL</label><input name="sgv" type="number" step="1" min="10" max="600" required></div>
      <div><label>时间（留空为现在）</label><input name="timestamp" type="datetime-local"></div>
    </div>
    <button>提交血糖</button>
    <span class="hint">写入 device = <?= htmlspecialchars($user['username']) ?>，仅你自己在面板可见。</span>
  </form>
</div>

<div class="card">
  <h1 style="font-size:16px;">治疗记录</h1>
  <p class="hint">选择事件类型后，表单只显示该类型需要的字段；不支持的类型不会写入 Nightscout。</p>
  <form method="post" action="/entry" id="treatmentForm">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
    <input type="hidden" name="kind" value="treatment">
    <label>事件类型</label>
    <select name="eventType" id="eventType" required></select>
    <div id="dynamicFields" class="grid"></div>
    <button>提交治疗记录</button>
    <span class="hint">写入 enteredBy = <?= htmlspecialchars($user['username']) ?>。</span>
  </form>
</div>

<script>
const TYPES = <?= json_encode($types, JSON_UNESCAPED_UNICODE) ?>;
const FIELDS = <?= json_encode($fields, JSON_UNESCAPED_UNICODE) ?>;
const GROUPS = { bolus: '大剂量 / 碳水', monitor: '监测与备注', temp: '临时与方案', care: '设备与护理' };
const PRESET = <?= json_encode($preset ?? '', JSON_UNESCAPED_UNICODE) ?>;

const typeSelect = document.getElementById('eventType');
const container = document.getElementById('dynamicFields');

/* Grouped type selector, built from the server-side registry. */
Object.keys(GROUPS).forEach(group => {
  const optgroup = document.createElement('optgroup');
  optgroup.label = GROUPS[group];
  Object.keys(TYPES).forEach(name => {
    if (TYPES[name][2] !== group) return;
    const opt = document.createElement('option');
    opt.value = name;
    opt.textContent = TYPES[name][0] + '（' + name + '）';
    if (name === PRESET) opt.selected = true;
    optgroup.appendChild(opt);
  });
  if (optgroup.children.length) typeSelect.appendChild(optgroup);
});

/* Rebuild the visible fields whenever the type changes. */
function renderFields () {
  container.innerHTML = '';
  const names = TYPES[typeSelect.value][1] || [];
  names.forEach(name => {
    const def = FIELDS[name];
    if (!def) return;
    const [kind, label, unit, min, max, step] = def;
    const wrap = document.createElement('div');
    const id = 'f_' + name;
    wrap.innerHTML = '<label for="' + id + '">' + label + (unit ? '（' + unit + '）' : '') + '</label>';
    const input = document.createElement('input');
    input.id = id;
    input.name = name;
    if (kind === 'number') {
      input.type = 'number';
      input.step = step || '1';
      if (min) input.min = min;
      if (max) input.max = max;
    } else {
      input.type = 'text';
      if (name === 'notes') input.placeholder = '可选说明';
    }
    wrap.appendChild(input);
    container.appendChild(wrap);
  });
  // Profile Switch defaults to the user's own plan name.
  if (typeSelect.value === 'Profile Switch') {
    const prof = document.getElementById('f_profile');
    if (prof && !prof.value) prof.value = <?= json_encode($user['username']) ?>;
  }
}

typeSelect.addEventListener('change', renderFields);
renderFields();
</script>
