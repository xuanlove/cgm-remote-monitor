<h1>我的 Profile（<?= htmlspecialchars($user['username']) ?>）</h1>

<?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if (!empty($success)): ?><p class="msg-ok">保存成功 —— 仅更新了你的 store 档案，其他用户的数据未受影响。</p><?php endif; ?>

<?php if (empty($profile)): ?>
  <div class="card"><p>尚未找到你的 Profile 档案，请联系管理员。</p></div>
<?php else: ?>
<form method="post" action="/profile">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
  <div class="card">
    <div class="grid">
      <div>
        <label>胰岛素作用时间 DIA（小时）</label>
        <input name="dia" type="number" step="0.1" value="<?= htmlspecialchars((string)($profile['dia'] ?? 3)) ?>">
        <label>碳水吸收速率（g/小时）</label>
        <input name="carbs_hr" type="number" step="1" value="<?= htmlspecialchars((string)($profile['carbs_hr'] ?? 30)) ?>">
        <label>时区</label>
        <input name="timezone" value="<?= htmlspecialchars((string)($profile['timezone'] ?? 'UTC')) ?>">
      </div>
      <div>
        <label>基础率 basal（偏移小时,值 U/行）</label>
        <textarea name="basal"><?php
          foreach (($profile['basal'] ?? []) as $pair) echo htmlspecialchars($pair[0] . ',' . $pair[1]) . "\n";
        ?></textarea>
      </div>
      <div>
        <label>敏感系数 sens（偏移小时,值 mg/dL per U/行）</label>
        <textarea name="sens"><?php
          foreach (($profile['sens'] ?? []) as $pair) echo htmlspecialchars($pair[0] . ',' . $pair[1]) . "\n";
        ?></textarea>
      </div>
      <div>
        <label>目标下限 target_low（偏移小时,值/行）</label>
        <textarea name="target_low"><?php
          foreach (($profile['target_low'] ?? []) as $pair) echo htmlspecialchars($pair[0] . ',' . $pair[1]) . "\n";
        ?></textarea>
        <label>目标上限 target_high（偏移小时,值/行）</label>
        <textarea name="target_high"><?php
          foreach (($profile['target_high'] ?? []) as $pair) echo htmlspecialchars($pair[0] . ',' . $pair[1]) . "\n";
        ?></textarea>
      </div>
    </div>
    <button>保存（仅写入 store[<?= htmlspecialchars($user['username']) ?>]）</button>
    <span class="hint">该请求使用你的受限 token 提交：Nightscout 服务端会剥离/拒绝任何触碰其他用户档案的内容。</span>
  </div>
</form>
<?php endif; ?>
