<div class="card" style="max-width:520px;margin:60px auto;">
  <h1>注册</h1>
  <?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>

  <?php if (!empty($success)): ?>
    <p class="msg-ok">账号开通成功！以下是与你的专属 Profile 绑定的 API 信息（请妥善保管）：</p>
    <table>
      <tr><th>Nightscout 地址</th><td><code><?= htmlspecialchars($info['nsUrl']) ?></code></td></tr>
      <tr><th>用户名 / Profile 名</th><td><code><?= htmlspecialchars($info['username']) ?></code></td></tr>
      <tr><th>你的 accessToken</th><td><code><?= htmlspecialchars($info['token']) ?></code></td></tr>
    </table>
    <p class="hint">该 token 只能读写以你用户名命名的 Profile 文档，以及上传血糖/治疗/活动数据，无法读取他人数据。详情见登录后的「我的 API」页。</p>
    <a href="/login"><button>去登录</button></a>
  <?php else: ?>
    <form method="post" action="/register">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
      <label>用户名（小写字母/数字/下划线/短横线，3-24 位；同时作为你专属 Profile 的名称）</label>
      <input name="username" required pattern="[a-z0-9][a-z0-9_-]{2,23}" autofocus>
      <label>密码（至少 8 位）</label>
      <input type="password" name="password" required minlength="8">
      <button>注册并开通</button>
    </form>
    <p class="hint">注册后系统会在 Nightscout 中自动为你创建以用户名命名的 Profile 档案与受限 API token。</p>
  <?php endif; ?>
</div>
