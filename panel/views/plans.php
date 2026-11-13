<h1><?= t('plans.title') ?> <span class="hint" style="font-size:13px;">· <?= t('plans.subtitle', ['name' => htmlspecialchars((string)$user['username'])]) ?></span></h1>

<?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if (!empty($success)): ?><p class="msg-ok"><?= htmlspecialchars($success) ?></p><?php endif; ?>

<div class="card">
  <table>
    <thead><tr>
      <th><?= t('plans.col.status') ?></th>
      <th><?= t('plans.col.startDate') ?></th>
      <th><?= t('plans.col.dia') ?></th>
      <th><?= t('plans.col.basalCount') ?></th>
      <th><?= t('plans.col.target') ?></th>
      <th><?= t('plans.col.ownership') ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php if (empty($plans)): ?>
      <tr><td colspan="7" class="hint"><?= t('plans.empty') ?></td></tr>
    <?php else: ?>
      <?php foreach ($plans as $p): ?>
      <tr>
        <td><?= $p['active'] ? '<strong style="color:#3fb27f;">' . t('plans.active') . '</strong>' : '<span class="hint">' . t('plans.history') . '</span>' ?></td>
        <td><?= htmlspecialchars($p['startDate'] !== '' ? date('Y-m-d H:i', strtotime($p['startDate'])) : '-') ?></td>
        <td><?= htmlspecialchars((string)($p['dia'] ?? '-')) ?></td>
        <td><?= htmlspecialchars((string)$p['basalCount']) ?></td>
        <td class="hint"><?= htmlspecialchars(($p['targetLow'] ?? '-') . ' ~ ' . ($p['targetHigh'] ?? '-')) ?> <?= t('plans.unitMgdl') ?></td>
        <td class="hint"><?= $p['shared'] ? t('plans.shared') : t('plans.own') ?></td>
        <td style="white-space:nowrap;">
          <?php if (!$p['active']): ?>
            <form method="post" action="/profile/plans/activate" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
              <input type="hidden" name="_id" value="<?= htmlspecialchars($p['id']) ?>">
              <button style="margin:0;padding:2px 10px;font-size:12px;"><?= t('plans.activate') ?></button>
            </form>
            <?php if (!$p['shared']): ?>
              <form method="post" action="/profile/plans/delete" style="display:inline;" onsubmit="return confirm('<?= htmlspecialchars(t('plans.confirmDelete')) ?>')">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
                <input type="hidden" name="_id" value="<?= htmlspecialchars($p['id']) ?>">
                <button class="danger" style="margin:0 0 0 6px;padding:2px 8px;font-size:12px;"><?= t('plans.delete') ?></button>
              </form>
            <?php endif; ?>
          <?php else: ?>
            <a href="/profile"><?= t('plans.editCurrent') ?></a>
            <form method="post" action="/profile/plans/activate" style="display:inline;margin-left:8px;">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
              <input type="hidden" name="_id" value="<?= htmlspecialchars($p['id']) ?>">
              <button style="margin:0;padding:2px 10px;font-size:12px;" title="<?= htmlspecialchars(t('plans.snapshotTitle')) ?>"><?= t('plans.snapshot') ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <p class="hint"><?= t('plans.note') ?></p>
</div>
