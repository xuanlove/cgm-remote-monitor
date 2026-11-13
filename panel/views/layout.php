<?php /** @var array|null $user */ ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nightscout 多用户面板</title>
<style>
  :root { --bg:#1a1f2b; --card:#232a38; --line:#313a4d; --fg:#e6ebf4; --muted:#8b95a9; --accent:#4f8ef7; --ok:#3fb27f; --bad:#e05a5a; --input-bg:#1b212d; --code-bg:#171c26; }
  html.light { --bg:#f2f4f8; --card:#ffffff; --line:#d8dee9; --fg:#1c2430; --muted:#5f6b7e; --accent:#2f6fe0; --ok:#22885e; --bad:#c73f3f; --input-bg:#eef1f6; --code-bg:#eef1f6; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--fg); font:14px/1.6 -apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif; }
  nav { display:flex; gap:16px; align-items:center; padding:10px 20px; background:var(--card); border-bottom:1px solid var(--line); }
  nav a { color:var(--fg); text-decoration:none; padding:4px 10px; border-radius:6px; }
  nav a:hover { background:var(--line); }
  nav .right { margin-left:auto; color:var(--muted); }
  main { max-width:1100px; margin:24px auto; padding:0 16px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:20px; margin-bottom:16px; }
  h1 { font-size:20px; margin:0 0 14px; }
  label { display:block; color:var(--muted); margin:10px 0 4px; font-size:13px; }
  input, select, textarea { width:100%; padding:8px 10px; background:var(--input-bg); border:1px solid var(--line); border-radius:6px; color:var(--fg); font:inherit; }
  textarea { min-height:64px; font-family:ui-monospace,monospace; }
  button { background:var(--accent); color:#fff; border:0; padding:8px 18px; border-radius:6px; cursor:pointer; font:inherit; margin-top:12px; }
  button.danger { background:var(--bad); }
  button.theme-toggle { background:transparent; margin:0; padding:4px 8px; font-size:16px; }
  .msg-ok { color:var(--ok); } .msg-bad { color:var(--bad); }
  table { width:100%; border-collapse:collapse; }
  th, td { text-align:left; padding:8px 10px; border-bottom:1px solid var(--line); font-size:13px; }
  th { color:var(--muted); font-weight:normal; }
  code, pre { background:var(--code-bg); border:1px solid var(--line); border-radius:6px; padding:2px 6px; font:12px ui-monospace,monospace; }
  pre { padding:12px; overflow-x:auto; white-space:pre-wrap; word-break:break-all; }
  .grid { display:grid; grid-template-columns:1fr 1fr; gap:0 20px; }
  .hint { color:var(--muted); font-size:12px; }
</style>
<script>
/* Apply the stored theme before first paint to avoid a flash. */
(function () {
  var t = localStorage.getItem('panel-theme') || 'dark';
  if (t === 'light') document.documentElement.classList.add('light');
})();
</script>
</head>
<body>
<nav>
  <strong>Nightscout 面板</strong>
  <button type="button" class="theme-toggle" id="themeToggle" title="切换日间/夜间模式"></button>
  <?php if ($user !== null): ?>
    <a href="/dashboard">仪表盘</a>
    <a href="/reports">统计报告</a>
    <a href="/profile">我的 Profile</a>
    <a href="/profile/plans">治疗方案</a>
    <a href="/entry">填写数据</a>
    <a href="/treatments">我的记录</a>
    <a href="/settings/alerts">提醒设置</a>
    <a href="/myapi">我的 API</a>
    <?php if (($user['role'] ?? '') === 'admin'): ?><a href="/admin/users">管理</a><?php endif; ?>
    <span class="right"><?= htmlspecialchars((string)($user['username'] ?? '')) ?> · <a href="/logout">退出</a></span>
  <?php endif; ?>
</nav>
<main>
<?php require __DIR__ . '/' . $template . '.php'; ?>
</main>
<script>
(function () {
  var btn = document.getElementById('themeToggle');
  function paint() {
    var light = document.documentElement.classList.contains('light');
    btn.textContent = light ? '🌙' : '☀️';
    btn.title = light ? '切换到夜间模式' : '切换到日间模式';
  }
  btn.addEventListener('click', function () {
    var light = document.documentElement.classList.toggle('light');
    localStorage.setItem('panel-theme', light ? 'light' : 'dark');
    paint();
  });
  paint();
})();
</script>
</body>
</html>
