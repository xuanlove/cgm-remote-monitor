<h1><?= t('api.title') ?></h1>

<div class="card">
  <h1 style="font-size:16px;"><?= t('api.infoTitle') ?></h1>
  <table>
    <tr><th><?= t('api.nsUrl') ?></th><td><code id="nsurl"><?= htmlspecialchars($nsUrl) ?></code></td></tr>
    <tr><th><?= t('api.profile') ?></th><td><code><?= htmlspecialchars($user['username']) ?></code></td></tr>
    <tr><th><?= t('api.token') ?></th><td><code id="tok" data-token="<?= htmlspecialchars($token) ?>"><?= htmlspecialchars(substr($token, 0, 6) . '-••••••••••••••••') ?></code>
      <button type="button" style="margin-left:8px;padding:2px 10px;" onclick="const t=document.getElementById('tok');t.textContent=t.dataset.token;"><?= t('api.show') ?></button></td></tr>
  </table>
  <p class="hint"><?= t('api.perms', ['name' => htmlspecialchars($user['username'])]) ?></p>
</div>

<div class="card">
  <h1 style="font-size:16px;"><?= t('api.readGlucose') ?></h1>
<pre>curl "<?= htmlspecialchars($nsUrl) ?>/api/v1/entries.json?count=100" \
  -H "api-secret: &lt;<?= t('api.token') ?>&gt;"</pre>
  <p class="hint"><?= t('api.readGlucoseHint', ['name' => htmlspecialchars($user['username'])]) ?></p>

  <h1 style="font-size:16px;"><?= t('api.readProfile') ?></h1>
<pre>curl <?= htmlspecialchars($nsUrl) ?>/api/v1/profile.json \
  -H "api-secret: &lt;<?= t('api.token') ?>&gt;"</pre>

  <h1 style="font-size:16px;"><?= t('api.updateProfile', ['name' => htmlspecialchars($user['username'])]) ?></h1>
<pre>curl -X PUT <?= htmlspecialchars($nsUrl) ?>/api/v1/profile.json \
  -H "api-secret: &lt;<?= t('api.token') ?>&gt;" -H "Content-Type: application/json" \
  -d '{"_id":"&lt;profile _id&gt;","store":{"<?= htmlspecialchars($user['username']) ?>":{ ... }}}'</pre>

  <h1 style="font-size:16px;"><?= t('api.postTreatment') ?></h1>
<pre>curl -X POST <?= htmlspecialchars($nsUrl) ?>/api/v1/treatments.json \
  -H "api-secret: &lt;<?= t('api.token') ?>&gt;" -H "Content-Type: application/json" \
  -d '{"eventType":"Meal Bolus","carbs":30,"insulin":3,"enteredBy":"<?= htmlspecialchars($user['username']) ?>"}'</pre>

  <h1 style="font-size:16px;"><?= t('api.postActivity') ?></h1>
<pre>curl -X POST <?= htmlspecialchars($nsUrl) ?>/api/v1/activity/ \
  -H "api-secret: &lt;<?= t('api.token') ?>&gt;" -H "Content-Type: application/json" \
  -d '{"eventType":"Exercise","duration":30,"enteredBy":"<?= htmlspecialchars($user['username']) ?>"}'</pre>

  <h1 style="font-size:16px;"><?= t('api.cgm') ?></h1>
  <p class="hint"><?= t('api.cgmHint', ['url' => htmlspecialchars($nsUrl), 'name' => htmlspecialchars($user['username'])]) ?></p>
</div>
