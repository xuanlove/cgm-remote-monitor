<?php
// The registry stores translation keys; resolve them once here so the page's
// JavaScript can render labels without a second round trip.
$jsTypes = [];
foreach ($types as $name => $def) {
    $jsTypes[$name] = ['label' => t($def[0]), 'fields' => $def[1], 'group' => $def[2]];
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
$jsGroups = [];
foreach (['bolus', 'monitor', 'temp', 'care'] as $group) {
    $jsGroups[$group] = t('group.' . $group);
}
?>
<h1><?= t('entry.title') ?></h1>

<?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if (!empty($success)): ?><p class="msg-ok"><?= htmlspecialchars($success) ?></p><?php endif; ?>

<div class="card">
  <h1 style="font-size:16px;"><?= t('entry.glucoseTitle') ?></h1>
  <form method="post" action="/entry">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
    <input type="hidden" name="kind" value="glucose">
    <div class="grid">
      <div><label><?= t('entry.glucoseValue') ?></label><input name="sgv" type="number" step="1" min="10" max="600" required></div>
      <div><label><?= t('entry.glucoseTime') ?></label><input name="timestamp" type="datetime-local"></div>
    </div>
    <button><?= t('entry.submitGlucose') ?></button>
    <span class="hint"><?= t('entry.glucoseNote', ['name' => htmlspecialchars($user['username'])]) ?></span>
  </form>
</div>

<div class="card">
  <h1 style="font-size:16px;"><?= t('entry.treatmentTitle') ?></h1>
  <p class="hint"><?= t('entry.treatmentHint') ?></p>
  <form method="post" action="/entry" id="treatmentForm">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
    <input type="hidden" name="kind" value="treatment">
    <label><?= t('entry.eventType') ?></label>
    <select name="eventType" id="eventType" required></select>
    <div id="dynamicFields" class="grid"></div>
    <button><?= t('entry.submitTreatment') ?></button>
    <span class="hint"><?= t('entry.treatmentNote', ['name' => htmlspecialchars($user['username'])]) ?></span>
  </form>
</div>

<div class="card">
  <h1 style="font-size:16px;"><?= t('entry.activityTitle') ?></h1>
  <form method="post" action="/entry">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
    <input type="hidden" name="kind" value="activity">
    <div class="grid">
      <div>
        <label><?= t('entry.activityType') ?></label>
        <select name="activityType">
          <option value="Exercise"><?= t('activity.exercise') ?></option>
          <option value="Work"><?= t('activity.work') ?></option>
          <option value="Sport"><?= t('activity.sport') ?></option>
          <option value="Walk"><?= t('activity.walk') ?></option>
          <option value="Run"><?= t('activity.run') ?></option>
        </select>
      </div>
      <div><label><?= t('field.duration') ?> (min)</label><input name="duration" type="number" step="1"></div>
    </div>
    <label><?= t('field.notes') ?></label><input name="notes">
    <button><?= t('entry.submitActivity') ?></button>
    <span class="hint"><?= t('entry.activityNote') ?></span>
  </form>
</div>

<script>
const TYPES = <?= js_json($jsTypes) ?>;
const FIELDS = <?= js_json($jsFields) ?>;
const GROUPS = <?= js_json($jsGroups) ?>;
const PRESET = <?= js_json($preset ?? '') ?>;

const typeSelect = document.getElementById('eventType');
const container = document.getElementById('dynamicFields');

/* Grouped type selector, built from the server-side registry. */
Object.keys(GROUPS).forEach(group => {
  const optgroup = document.createElement('optgroup');
  optgroup.label = GROUPS[group];
  Object.keys(TYPES).forEach(name => {
    if (TYPES[name].group !== group) return;
    const opt = document.createElement('option');
    opt.value = name;
    opt.textContent = TYPES[name].label + ' (' + name + ')';
    if (name === PRESET) opt.selected = true;
    optgroup.appendChild(opt);
  });
  if (optgroup.children.length) typeSelect.appendChild(optgroup);
});

/* Rebuild the visible fields whenever the type changes. */
function renderFields () {
  container.innerHTML = '';
  (TYPES[typeSelect.value].fields || []).forEach(name => {
    const def = FIELDS[name];
    if (!def) return;
    const wrap = document.createElement('div');
    const id = 'f_' + name;
    const label = document.createElement('label');
    label.htmlFor = id;
    label.textContent = def.label + (def.unit ? ' (' + def.unit + ')' : '');
    wrap.appendChild(label);
    const input = document.createElement('input');
    input.id = id;
    input.name = name;
    if (def.kind === 'number') {
      input.type = 'number';
      input.step = def.step || '1';
      if (def.min) input.min = def.min;
      if (def.max) input.max = def.max;
    } else {
      input.type = 'text';
    }
    wrap.appendChild(input);
    container.appendChild(wrap);
  });
  // Profile Switch defaults to the user's own plan name.
  if (typeSelect.value === 'Profile Switch') {
    const prof = document.getElementById('f_profile');
    if (prof && !prof.value) prof.value = <?= js_json($user['username']) ?>;
  }
}

typeSelect.addEventListener('change', renderFields);
renderFields();
</script>
