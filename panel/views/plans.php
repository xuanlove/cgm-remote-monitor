<h1>治疗方案 <span class="hint" style="font-size:13px;">· 以 <?= htmlspecialchars($user['username']) ?> 名下的档案版本（startDate 倒序，第一条为当前生效）</span></h1>

<?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if (!empty($success)): ?><p class="msg-ok"><?= htmlspecialchars($success) ?></p><?php endif; ?>

<div class="card">
  <table>
    <thead><tr><th>状态</th><th>开始时间</th><th>DIA</th><th>基础率段数</th><th>目标范围</th><th>记录属性</th><th></th></tr></thead>
    <tbody>
    <?php if (empty($plans)): ?>
      <tr><td colspan="7" class="hint">还没有方案 —— 到「我的 Profile」保存一次即可生成当前方案。</td></tr>
    <?php else: ?>
      <?php foreach ($plans as $p): ?>
      <tr>
        <td><?= $p['active'] ? '<strong style="color:#3fb27f;">当前生效</strong>' : '<span class="hint">历史</span>' ?></td>
        <td><?= htmlspecialchars($p['startDate'] !== '' ? date('Y-m-d H:i', strtotime($p['startDate'])) : '-') ?></td>
        <td><?= htmlspecialchars((string)($p['dia'] ?? '-')) ?></td>
        <td><?= htmlspecialchars((string)$p['basalCount']) ?></td>
        <td class="hint"><?= htmlspecialchars(($p['targetLow'] ?? '-') . ' ~ ' . ($p['targetHigh'] ?? '-')) ?> mg/dL</td>
        <td class="hint"><?= $p['shared'] ? '与其他用户共用同一记录' : '仅含你的档案' ?></td>
        <td style="white-space:nowrap;">
          <?php if (!$p['active']): ?>
            <form method="post" action="/profile/plans/activate" style="display:inline;">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
              <input type="hidden" name="_id" value="<?= htmlspecialchars($p['id']) ?>">
              <button style="margin:0;padding:2px 10px;font-size:12px;">启用此方案</button>
            </form>
            <?php if (!$p['shared']): ?>
              <form method="post" action="/profile/plans/delete" style="display:inline;" onsubmit="return confirm('删除这个方案版本？')">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
                <input type="hidden" name="_id" value="<?= htmlspecialchars($p['id']) ?>">
                <button class="danger" style="margin:0 0 0 6px;padding:2px 8px;font-size:12px;">删除</button>
              </form>
            <?php endif; ?>
          <?php else: ?>
            <a href="/profile">编辑当前方案</a>
            <form method="post" action="/profile/plans/activate" style="display:inline;margin-left:8px;">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
              <input type="hidden" name="_id" value="<?= htmlspecialchars($p['id']) ?>">
              <button style="margin:0;padding:2px 10px;font-size:12px;" title="把当前方案另存为一个新版本（时间戳为现在），原版本保留为历史">保存为新版本</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
  <p class="hint">「启用此方案」会以该版本内容 + 当前时间写入一条新的档案记录（成为当前生效）；历史版本可按需删除，共用的记录与他人的档案不受影响。</p>
</div>
