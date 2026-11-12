<h1>填写数据</h1>

<?php if (!empty($error)): ?><p class="msg-bad"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<?php if (!empty($success)): ?><p class="msg-ok"><?= htmlspecialchars($success) ?></p><?php endif; ?>

<form method="post" action="/entry">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">

  <div class="card">
    <h1 style="font-size:16px;">手填血糖</h1>
    <input type="hidden" name="kind" value="glucose">
    <div class="grid">
      <div><label>血糖值 mg/dL</label><input name="sgv" type="number" step="1" min="10" max="600" required></div>
      <div><label>时间（留空为现在）</label><input name="timestamp" type="datetime-local"></div>
    </div>
    <button>提交血糖</button>
    <span class="hint">写入 device = <?= htmlspecialchars($user['username']) ?>，仅你自己在面板可见。</span>
  </div>
</form>

<form method="post" action="/entry">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
  <div class="card">
    <h1 style="font-size:16px;">治疗记录</h1>
    <input type="hidden" name="kind" value="treatment">
    <div class="grid">
      <div><label>事件类型</label>
        <select name="eventType">
          <option value="Meal Bolus">正餐大剂量</option>
          <option value="Snack Bolus">加餐大剂量</option>
          <option value="Correction Bolus">纠正大剂量</option>
          <option value="Carb Correction">碳水修正</option>
          <option value="BG Check">测量血糖</option>
          <option value="Note">备注</option>
        </select>
      </div>
      <div><label>血糖 mg/dL（可空）</label><input name="glucose" type="number" step="0.1"></div>
      <div><label>碳水 g（可空）</label><input name="carbs" type="number" step="1"></div>
      <div><label>胰岛素 U（可空）</label><input name="insulin" type="number" step="0.05"></div>
    </div>
    <label>备注</label><input name="notes">
    <button>提交治疗</button>
    <span class="hint">写入 enteredBy = <?= htmlspecialchars($user['username']) ?>。</span>
  </div>
</form>

<form method="post" action="/entry">
  <input type="hidden" name="csrf" value="<?= htmlspecialchars(\NightPanel\Auth::csrfToken()) ?>">
  <div class="card">
    <h1 style="font-size:16px;">活动记录（exercise）</h1>
    <input type="hidden" name="kind" value="activity">
    <div class="grid">
      <div><label>活动类型</label>
        <select name="activityType">
          <option value="Exercise">运动</option>
          <option value="Work">劳作</option>
          <option value="Sport">竞技运动</option>
          <option value="Walk">步行</option>
          <option value="Run">跑步</option>
        </select>
      </div>
      <div><label>时长（分钟）</label><input name="duration" type="number" step="1"></div>
    </div>
    <label>备注</label><input name="notes">
    <button>提交活动</button>
    <span class="hint">该记录使用你的 api:activity:create 权限对应的归属字段写入。</span>
  </div>
</form>
