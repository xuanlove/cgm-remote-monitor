<h1>我的 API</h1>

<div class="card">
  <h1 style="font-size:16px;">你的专属接口信息</h1>
  <table>
    <tr><th>Nightscout 地址</th><td><code id="nsurl"><?= htmlspecialchars($nsUrl) ?></code></td></tr>
    <tr><th>你的用户名 / Profile 名</th><td><code><?= htmlspecialchars($user['username']) ?></code></td></tr>
    <tr><th>accessToken</th><td><code id="tok" data-token="<?= htmlspecialchars($token) ?>"><?= htmlspecialchars(substr($token, 0, 6) . '-••••••••••••••••') ?></code>
      <button type="button" style="margin-left:8px;padding:2px 10px;" onclick="const t=document.getElementById('tok');t.textContent=t.dataset.token;">显示</button></td></tr>
  </table>
  <p class="hint">token 权限：读写以你用户名命名的 Profile 文档（api:profile:<?= htmlspecialchars($user['username']) ?>:read/write）+ 上传血糖/治疗/活动数据（仅创建）。无法读取任何集合数据。</p>
</div>

<div class="card">
  <h1 style="font-size:16px;">读取你的 Profile（只返回你自己的档案）</h1>
<pre>curl <?= htmlspecialchars($nsUrl) ?>/api/v1/profile.json \
  -H "api-secret: &lt;你的token&gt;"</pre>

  <h1 style="font-size:16px;">更新你的 Profile（仅 store[<?= htmlspecialchars($user['username']) ?>] 生效）</h1>
<pre>curl -X PUT <?= htmlspecialchars($nsUrl) ?>/api/v1/profile.json \
  -H "api-secret: &lt;你的token&gt;" -H "Content-Type: application/json" \
  -d '{"_id":"&lt;profile文档id&gt;","store":{"<?= htmlspecialchars($user['username']) ?>":{ ...你的档案... }}}'</pre>

  <h1 style="font-size:16px;">上传治疗记录</h1>
<pre>curl -X POST <?= htmlspecialchars($nsUrl) ?>/api/v1/treatments.json \
  -H "api-secret: &lt;你的token&gt;" -H "Content-Type: application/json" \
  -d '{"eventType":"Meal Bolus","carbs":30,"insulin":3,"enteredBy":"<?= htmlspecialchars($user['username']) ?>"}'</pre>

  <h1 style="font-size:16px;">上传活动记录</h1>
<pre>curl -X POST <?= htmlspecialchars($nsUrl) ?>/api/v1/activity/ \
  -H "api-secret: &lt;你的token&gt;" -H "Content-Type: application/json" \
  -d '{"eventType":"Exercise","duration":30,"enteredBy":"<?= htmlspecialchars($user['username']) ?>"}'</pre>

  <h1 style="font-size:16px;">CGM 上传器配置指引</h1>
  <p class="hint">将 CGM 上传器（xDrip+/Loop/AAPS 等）的 Nightscout 地址指向 <code><?= htmlspecialchars($nsUrl) ?></code>，
  token 填你的 accessToken，并把设备名（device）设置为你的用户名 <code><?= htmlspecialchars($user['username']) ?></code> —— 面板按该归属展示你的曲线。</p>
</div>
