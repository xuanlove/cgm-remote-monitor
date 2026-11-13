<div class="card" style="max-width:420px;margin:60px auto;">
  <h1><?= t('login.title') ?></h1>
  <?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <form method="post" action="/login">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
    <label><?= t('login.username') ?></label>
    <input name="username" required autofocus>
    <label><?= t('login.password') ?></label>
    <input type="password" name="password" required>
    <button><?= t('login.submit') ?></button>
  </form>
  <p class="hint"><?= t('login.noAccount') ?><a href="/register"><?= t('login.register') ?></a></p>
</div>
