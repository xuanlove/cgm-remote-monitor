<h1>提醒设置 <span class="hint" style="font-size:13px;">· 仅影响你在本面板的告警，不写入 Nightscout 档案</span></h1>

<?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if (!empty($success)): ?><p class="msg-ok"><?= htmlspecialchars($success) ?></p><?php endif; ?>

<div class="card" style="max-width:560px;">
  <form method="post" action="/settings/alerts">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">

    <label style="display:flex;gap:8px;align-items:center;">
      <input type="checkbox" name="enabled" value="1" style="width:auto;" <?= $settings['enabled'] ? 'checked' : '' ?>>
      启用提醒线
    </label>

    <div class="grid">
      <div>
        <label>低值提醒（低于此值时告警）</label>
        <input name="low" type="number" step="0.1" min="2" max="10" value="<?= htmlspecialchars((string)$settings['low']) ?>" required>
        <span class="hint">mmol/L，范围 2.0–10.0</span>
      </div>
      <div>
        <label>高值提醒（高于此值时告警）</label>
        <input name="high" type="number" step="0.1" min="5" max="25" value="<?= htmlspecialchars((string)$settings['high']) ?>" required>
        <span class="hint">mmol/L，范围 5.0–25.0</span>
      </div>
    </div>

    <label style="display:flex;gap:8px;align-items:center;margin-top:14px;">
      <input type="checkbox" name="browser" value="1" style="width:auto;" <?= $settings['browser'] ? 'checked' : '' ?>>
      同时发送浏览器桌面通知（需在仪表盘授权一次）
    </label>

    <button>保存设置</button>
    <a href="/dashboard" style="margin-left:12px;">返回仪表盘</a>
  </form>

  <div style="margin-top:16px;">
    <button type="button" onclick="askPermission()" style="background:transparent;border:1px solid var(--line);color:var(--fg);">
      授权浏览器通知
    </button>
    <span id="permState" class="hint"></span>
  </div>
</div>

<script>
function paintPermission () {
  const el = document.getElementById('permState');
  if (typeof Notification === 'undefined') {
    el.textContent = '当前浏览器不支持桌面通知';
    return;
  }
  const map = { granted: '已授权', denied: '已被拒绝（需在浏览器设置中恢复）', default: '尚未授权' };
  el.textContent = map[Notification.permission] || Notification.permission;
  el.className = Notification.permission === 'granted' ? 'msg-ok' : 'hint';
}

function askPermission () {
  if (typeof Notification === 'undefined') return;
  Notification.requestPermission().then(paintPermission);
}

paintPermission();
</script>

<div class="card" style="max-width:560px;">
  <h1 style="font-size:15px;">说明</h1>
  <ul class="hint" style="margin:0;padding-left:18px;">
    <li>阈值只保存在面板数据库（与你账号绑定），不写入 Nightscout，因此不会影响泵或闭环算法。</li>
    <li>仪表盘越线时显示彩色横幅；勾选浏览器通知后，还会推送一条桌面通知（同一区间内不重复打扰）。</li>
    <li>曲线上的目标区间带仍以你的 Profile 目标范围为准，与这里的提醒阈值互不影响。</li>
  </ul>
</div>
