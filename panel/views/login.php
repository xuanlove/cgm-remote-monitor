<div class="card" style="max-width:420px;margin:60px auto;">
  <h1>登录</h1>
  <?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <form method="post" action="/login">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
    <label>用户名</label>
    <input name="username" required autofocus>
    <label>密码</label>
    <input type="password" name="password" required>
    <button>登录</button>
  </form>
  <p class="hint">还没有账号？<a href="/register">注册</a></p>
</div>
