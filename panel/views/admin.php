<h1><?= t('adm.title') ?></h1>

<div class="card">
  <h1 style="font-size:16px;"><?= t('adm.nsStatus') ?></h1>
  <?php if (!empty($nsError)): ?>
    <p class="msg-bad"><?= htmlspecialchars($nsError) ?></p>
    <form method="post" action="/admin/settings">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
      <div class="grid">
        <div><label><?= t('adm.url') ?></label><input name="ns_url" placeholder="http://127.0.0.1:1337"></div>
        <div><label><?= t('adm.secret') ?></label><input name="ns_secret" type="password"></div>
      </div>
      <button><?= t('adm.saveConfig') ?></button>
      <span class="hint"><?= t('adm.envNote') ?></span>
    </form>
  <?php else: ?>
    <p class="msg-ok"><?= t('adm.connected', [
      'version' => htmlspecialchars((string)($nsStatus['version'] ?? '?')),
      'state' => htmlspecialchars((string)($nsStatus['runtimeState'] ?? '?')),
      'language' => htmlspecialchars((string)($nsStatus['settings']['language'] ?? '?')),
    ]) ?></p>
    <form method="post" action="/admin/settings">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
      <div class="grid">
        <div><label><?= t('adm.urlOverride') ?></label><input name="ns_url" placeholder="<?= t('adm.keepEmpty') ?>"></div>
        <div><label><?= t('adm.secretOverride') ?></label><input name="ns_secret" type="password" placeholder="<?= t('adm.keepEmpty') ?>"></div>
      </div>
      <button><?= t('adm.updateConfig') ?></button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h1 style="font-size:16px;"><?= t('adm.users', ['n' => count($users)]) ?></h1>
  <table>
    <thead><tr>
      <th><?= t('adm.col.id') ?></th>
      <th><?= t('adm.col.username') ?></th>
      <th><?= t('adm.col.role') ?></th>
      <th><?= t('adm.col.subject') ?></th>
      <th><?= t('adm.col.createdAt') ?></th>
      <th></th>
    </tr></thead>
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
          <form method="post" action="/admin/users/delete" onsubmit="return confirm('<?= htmlspecialchars(t('adm.confirmDelete', ['name' => $u['username']])) ?>')">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <button class="danger" style="margin:0;padding:4px 10px;"><?= t('adm.delete') ?></button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
