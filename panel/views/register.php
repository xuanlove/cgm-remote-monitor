<div class="card" style="max-width:520px;margin:60px auto;">
  <h1><?= t('register.title') ?></h1>
  <?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>

  <?php if (!empty($success)): ?>
    <p class="msg-ok"><?= t('register.successIntro') ?></p>
    <table>
      <tr><th><?= t('register.nsUrl') ?></th><td><code><?= htmlspecialchars($info['nsUrl']) ?></code></td></tr>
      <tr><th><?= t('register.profile') ?></th><td><code><?= htmlspecialchars($info['username']) ?></code></td></tr>
      <tr><th><?= t('register.token') ?></th><td><code><?= htmlspecialchars($info['token']) ?></code></td></tr>
    </table>
    <p class="hint"><?= t('register.tokenHint') ?></p>
    <a href="/login"><button><?= t('register.goLogin') ?></button></a>
  <?php else: ?>
    <form method="post" action="/register">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
      <label><?= t('register.usernameLabel') ?></label>
      <input name="username" required pattern="[a-z0-9][a-z0-9_-]{2,23}" autofocus>
      <label><?= t('register.passwordLabel') ?></label>
      <input type="password" name="password" required minlength="8">
      <button><?= t('register.submit') ?></button>
    </form>
    <p class="hint"><?= t('register.hint') ?></p>
  <?php endif; ?>
</div>
