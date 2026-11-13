<h1><?= t('prof.title', ['name' => htmlspecialchars($user['username'])]) ?></h1>

<?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if (!empty($success)): ?><p class="msg-ok"><?= t('prof.saved') ?></p><?php endif; ?>

<?php if (empty($profile)): ?>
  <div class="card"><p><?= t('prof.missing') ?></p></div>
<?php else: ?>
<form method="post" action="/profile">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
  <div class="card">
    <div class="grid">
      <div>
        <label><?= t('prof.dia') ?></label>
        <input name="dia" type="number" step="0.1" value="<?= htmlspecialchars((string)($profile['dia'] ?? 3)) ?>">
        <label><?= t('prof.carbsHr') ?></label>
        <input name="carbs_hr" type="number" step="1" value="<?= htmlspecialchars((string)($profile['carbs_hr'] ?? 30)) ?>">
        <label><?= t('prof.timezone') ?></label>
        <input name="timezone" value="<?= htmlspecialchars((string)($profile['timezone'] ?? 'UTC')) ?>">
      </div>
      <div>
        <label><?= t('prof.basal') ?></label>
        <textarea name="basal"><?php
          foreach (($profile['basal'] ?? []) as $pair) echo htmlspecialchars($pair[0] . ',' . $pair[1]) . "\n";
        ?></textarea>
      </div>
      <div>
        <label><?= t('prof.sens') ?></label>
        <textarea name="sens"><?php
          foreach (($profile['sens'] ?? []) as $pair) echo htmlspecialchars($pair[0] . ',' . $pair[1]) . "\n";
        ?></textarea>
      </div>
      <div>
        <label><?= t('prof.targetLow') ?></label>
        <textarea name="target_low"><?php
          foreach (($profile['target_low'] ?? []) as $pair) echo htmlspecialchars($pair[0] . ',' . $pair[1]) . "\n";
        ?></textarea>
        <label><?= t('prof.targetHigh') ?></label>
        <textarea name="target_high"><?php
          foreach (($profile['target_high'] ?? []) as $pair) echo htmlspecialchars($pair[0] . ',' . $pair[1]) . "\n";
        ?></textarea>
      </div>
    </div>
    <button><?= t('prof.save', ['name' => htmlspecialchars($user['username'])]) ?></button>
    <span class="hint"><?= t('prof.scopeNote') ?></span>
  </div>
</form>
<?php endif; ?>
