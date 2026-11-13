<h1>我的记录 <span class="hint" style="font-size:13px;">· enteredBy = <?= htmlspecialchars($user['username']) ?></span></h1>

<?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if (!empty($success)): ?><p class="msg-ok"><?= htmlspecialchars($success) ?></p><?php endif; ?>

<?php if (!empty($editing)): ?>
<div class="card">
  <h1 style="font-size:16px;">编辑记录 <span class="hint">#<?= htmlspecialchars((string)$editing['_id']) ?></span></h1>
  <form method="post" action="/treatments/save" id="editForm">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
    <input type="hidden" name="_id" value="<?= htmlspecialchars((string)$editing['_id']) ?>">
    <label>事件类型</label>
    <select name="eventType" id="editEventType"></select>
    <div id="editFields" class="grid"></div>
    <button>保存修改</button>
    <a href="/treatments" style="margin-left:12px;">取消</a>
    <span class="hint">归属 enteredBy 由服务端保留，无法改到他人名下。</span>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
    <span class="hint">导出：</span>
    <a href="/export/treatments.csv?days=14" class="hint">治疗 CSV（14 天）</a>
    <a href="/export/treatments.csv?days=30" class="hint">治疗 CSV（30 天）</a>
    <a href="/export/entries.csv?days=14" class="hint">血糖 CSV（14 天）</a>
  </div>
  <table>
    <thead><tr><th>时间</th><th>类型</th><th>血糖</th><th>碳水</th><th>胰岛素</th><th>时长/基础率</th><th>备注</th><th></th></tr></thead>
    <tbody>
    <?php if (empty($treatments)): ?>
      <tr><td colspan="8" class="hint">还没有记录，去「填写数据」添加。</td></tr>
    <?php else: ?>
      <?php foreach ($treatments as $t): ?>
      <tr>
        <td><?= htmlspecialchars(date('Y-m-d H:i', strtotime((string)($t['created_at'] ?? 'now')))) ?></td>
        <td><?= htmlspecialchars((string)($t['eventType'] ?? '-')) ?></td>
        <td><?= htmlspecialchars((string)($t['glucose'] ?? '-')) ?></td>
        <td><?= htmlspecialchars((string)($t['carbs'] ?? '-')) ?></td>
        <td><?= htmlspecialchars((string)($t['insulin'] ?? '-')) ?></td>
        <td class="hint">
          <?php
            $extra = [];
            if (isset($t['duration'])) $extra[] = (int)$t['duration'] . ' 分钟';
            if (isset($t['absolute'])) $extra[] = (float)$t['absolute'] . ' U/h';
            if (isset($t['percent'])) $extra[] = (int)$t['percent'] . '%';
            if (isset($t['targetLow']) || isset($t['targetBottom'])) $extra[] = '目标 ' . (int)($t['targetBottom'] ?? $t['targetLow'] ?? 0) . '-' . (int)($t['targetTop'] ?? $t['targetHigh'] ?? 0);
            echo htmlspecialchars($extra ? implode(' / ', $extra) : '-');
          ?>
        </td>
        <td><?= htmlspecialchars((string)($t['notes'] ?? '')) ?></td>
        <td style="white-space:nowrap;">
          <a href="/treatments?edit=<?= urlencode((string)$t['_id']) ?>">编辑</a>
          <form method="post" action="/treatments/delete" style="display:inline;" onsubmit="return confirm('删除这条记录？')">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
            <input type="hidden" name="_id" value="<?= htmlspecialchars((string)$t['_id']) ?>">
            <button class="danger" style="margin:0 0 0 8px;padding:2px 8px;font-size:12px;">删除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if (!empty($editing)): ?>
<script>
const TYPES = <?= json_encode($types, JSON_UNESCAPED_UNICODE) ?>;
const FIELDS = <?= json_encode($fields, JSON_UNESCAPED_UNICODE) ?>;
const RECORD = <?= json_encode($editing, JSON_UNESCAPED_UNICODE) ?>;

const typeSelect = document.getElementById('editEventType');
const container = document.getElementById('editFields');

Object.keys(TYPES).forEach(name => {
  const opt = document.createElement('option');
  opt.value = name;
  opt.textContent = TYPES[name][0] + '（' + name + '）';
  if (name === RECORD.eventType) opt.selected = true;
  typeSelect.appendChild(opt);
});

function renderFields () {
  container.innerHTML = '';
  (TYPES[typeSelect.value][1] || []).forEach(name => {
    const def = FIELDS[name];
    if (!def) return;
    const [kind, label, unit, min, max, step] = def;
    const wrap = document.createElement('div');
    wrap.innerHTML = '<label for="e_' + name + '">' + label + (unit ? '（' + unit + '）' : '') + '</label>';
    const input = document.createElement('input');
    input.id = 'e_' + name;
    input.name = name;
    if (kind === 'number') {
      input.type = 'number';
      input.step = step || '1';
      if (min) input.min = min;
      if (max) input.max = max;
    } else {
      input.type = 'text';
    }
    if (RECORD[name] !== undefined && RECORD[name] !== null) input.value = RECORD[name];
    wrap.appendChild(input);
    container.appendChild(wrap);
  });
}

typeSelect.addEventListener('change', renderFields);
renderFields();
</script>
<?php endif; ?>
