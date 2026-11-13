<div class="card" style="max-width:640px;margin:60px auto;">
  <h1><?= t('err.title') ?></h1>
  <p class="msg-bad"><?= htmlspecialchars((string)($message ?? t('err.unknown'))) ?></p>
  <p><a href="/dashboard"><?= t('err.back') ?></a></p>
</div>
