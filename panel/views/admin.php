<h1>管理 · 用户与项目</h1>

<div class="card">
  <h1 style="font-size:16px;">Nightscout 后端状态</h1>
  <?php if (!empty($nsError)): ?>
    <p class="msg-bad"><?= htmlspecialchars($nsError) ?></p>
    <form method="post" action="/admin/settings">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
      <div class="grid">
        <div><label>Nightscout URL</label><input name="ns_url" placeholder="http://127.0.0.1:1337"></div>
        <div><label>API_SECRET（原文）</label><input name="ns_secret" type="password"></div>
      </div>
      <button>保存配置</button>
      <span class="hint">也可用环境变量 NIGHTSCOUT_URL / NIGHTSCOUT_ADMIN_SECRET 配置（优先级更高）。</span>
    </form>
  <?php else: ?>
    <p class="msg-ok">已连接：<?= htmlspecialchars((string)($nsStatus['version'] ?? '?')) ?>
      · 状态 <?= htmlspecialchars((string)($nsStatus['runtimeState'] ?? '?')) ?>
      · 默认语言 <?= htmlspecialchars((string)($nsStatus['settings']['language'] ?? '?')) ?></p>
    <form method="post" action="/admin/settings">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
      <div class="grid">
        <div><label>Nightscout URL（覆盖）</label><input name="ns_url" placeholder="留空保持不变"></div>
        <div><label>API_SECRET（覆盖）</label><input name="ns_secret" type="password" placeholder="留空保持不变"></div>
      </div>
      <button>更新配置</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h1 style="font-size:16px;">用户列表（<?= count($users) ?>）</h1>
  <table>
    <thead><tr><th>ID</th><th>用户名</th><th>角色</th><th>Nightscout subject</th><th>注册时间</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= htmlspecialchars((string)$u['id']) ?></td>
        <td><code><?= htmlspecialchars($u['username']) ?></code></td>
        <td><?= htmlspecialchars($u['role']) ?></td>
        <td class="hint"><?= htmlspecialchars((string)($u['ns_subject_id'] ?? '-')) ?></td>
        <td class="hint"><?= htmlspecialchars((string)$u['created_at']) ?></td>
        <td>
          <?php if ($u['role'] !== 'admin'): ?>
          <form method="post" action="/admin/users/delete" onsubmit="return confirm('删除用户 <?= htmlspecialchars($u['username']) ?> 及其 Nightscout subject？')">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="danger" style="margin:0;padding:4px 10px;">删除</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
