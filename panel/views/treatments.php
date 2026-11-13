<?php
// Resolve the registry's translation keys once for the edit form's JavaScript.
$jsTypes = [];
foreach ($types as $name => $def) {
    $jsTypes[$name] = ['label' => t($def[0]), 'fields' => $def[1]];
}
$jsFields = [];
foreach ($fields as $name => $def) {
    $jsFields[$name] = [
        'kind' => $def[0],
        'label' => t($def[1]),
        'unit' => $def[2],
        'min' => $def[3],
        'max' => $def[4],
        'step' => $def[5],
    ];
}
?>
<h1><?= t('tr.title') ?> <span class="hint" style="font-size:13px;">· <?= t('tr.owner', ['name' => htmlspecialchars($user['username'])]) ?></span></h1>

<?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if (!empty($success)): ?><p class="msg-ok"><?= htmlspecialchars($success) ?></p><?php endif; ?>

<?php if (!empty($editing)): ?>
<div class="card">
  <h1 style="font-size:16px;"><?= t('tr.editTitle') ?> <span class="hint">#<?= htmlspecialchars((string)$editing['_id']) ?></span></h1>
  <form method="post" action="/treatments/save" id="editForm">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
    <input type="hidden" name="_id" value="<?= htmlspecialchars((string)$editing['_id']) ?>">
    <label><?= t('entry.eventType') ?></label>
    <select name="eventType" id="editEventType"></select>
    <div id="editFields" class="grid"></div>
    <button><?= t('tr.save') ?></button>
    <a href="/treatments" style="margin-left:12px;"><?= t('tr.cancel') ?></a>
    <span class="hint"><?= t('tr.saveHint') ?></span>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
    <span class="hint"><?= t('tr.export') ?></span>
    <a href="/export/treatments.csv?days=14" class="hint"><?= t('tr.exportTreatments14') ?></a>
    <a href="/export/treatments.csv?days=30" class="hint"><?= t('tr.exportTreatments30') ?></a>
    <a href="/export/entries.csv?days=14" class="hint"><?= t('tr.exportGlucose14') ?></a>
  </div>
  <table>
    <thead><tr>
      <th><?= t('col.time') ?></th>
      <th><?= t('col.type') ?></th>
      <th><?= t('col.glucose') ?></th>
      <th><?= t('col.carbs') ?></th>
      <th><?= t('col.insulin') ?></th>
      <th><?= t('col.extra') ?></th>
      <th><?= t('col.notes') ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php if (empty($treatments)): ?>
      <tr><td colspan="8" class="hint"><?= t('tr.empty') ?></td></tr>
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
            if (isset($t['duration'])) $extra[] = t('col.minutes', ['n' => (int)$t['duration']]);
            if (isset($t['absolute'])) $extra[] = t('col.unitPerHour', ['v' => (float)$t['absolute']]);
            if (isset($t['percent'])) $extra[] = (int)$t['percent'] . '%';
            if (isset($t['targetLow']) || isset($t['targetBottom'])) {
                $extra[] = t('col.target', [
                    'low' => (int)($t['targetBottom'] ?? $t['targetLow'] ?? 0),
                    'high' => (int)($t['targetTop'] ?? $t['targetHigh'] ?? 0),
                ]);
            }
            echo htmlspecialchars($extra ? implode(' / ', $extra) : '-');
          ?>
        </td>
        <td><?= htmlspecialchars((string)($t['notes'] ?? '')) ?></td>
        <td style="white-space:nowrap;">
          <a href="/treatments?edit=<?= urlencode((string)$t['_id']) ?>"><?= t('col.edit') ?></a>
          <form method="post" action="/treatments/delete" style="display:inline;" onsubmit="return confirm('<?= htmlspecialchars(t('tr.confirmDelete')) ?>')">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
            <input type="hidden" name="_id" value="<?= htmlspecialchars((string)$t['_id']) ?>">
            <button class="danger" style="margin:0 0 0 8px;padding:2px 8px;font-size:12px;"><?= t('col.delete') ?></button>
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
const TYPES = <?= json_encode($jsTypes, JSON_UNESCAPED_UNICODE) ?>;
const FIELDS = <?= json_encode($jsFields, JSON_UNESCAPED_UNICODE) ?>;
const RECORD = <?= json_encode($editing, JSON_UNESCAPED_UNICODE) ?>;

const typeSelect = document.getElementById('editEventType');
const container = document.getElementById('editFields');

Object.keys(TYPES).forEach(name => {
  const opt = document.createElement('option');
  opt.value = name;
  opt.textContent = TYPES[name].label + ' (' + name + ')';
  if (name === RECORD.eventType) opt.selected = true;
  typeSelect.appendChild(opt);
});

function renderFields () {
  container.innerHTML = '';
  (TYPES[typeSelect.value].fields || []).forEach(name => {
    const def = FIELDS[name];
    if (!def) return;
    const wrap = document.createElement('div');
    wrap.innerHTML = '<label for="e_' + name + '">' + def.label + (def.unit ? ' (' + def.unit + ')' : '') + '</label>';
    const input = document.createElement('input');
    input.id = 'e_' + name;
    input.name = name;
    if (def.kind === 'number') {
      input.type = 'number';
      input.step = def.step || '1';
      if (def.min) input.min = def.min;
      if (def.max) input.max = def.max;
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
