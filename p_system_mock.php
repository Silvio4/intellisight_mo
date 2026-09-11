<?php
require __DIR__ . '/includes/auth.php';
$taskId = parse_task_id($_GET['id'] ?? 0);
if ($taskId < 1) { http_response_code(422); exit('缺少任务号'); }
$stmt = db()->prepare('SELECT id,status,vendor_part_no,description,upc_code FROM contract_forms WHERE id=:id LIMIT 1');
$stmt->execute([':id' => $taskId]);
$task = $stmt->fetch();
if (!$task) { http_response_code(404); exit('任务不存在'); }
$vendors = split_result_values($task['vendor_part_no']);
$descriptions = split_result_values($task['description']);
$upcs = split_result_values($task['upc_code']);
$rowCount = max(count($vendors), count($descriptions));
$pageTitle = '模拟 P 系统';
require __DIR__ . '/includes/layout_top.php';
?>
<div class="page-head"><div><h2>模拟 P 系统匹配</h2><p>任务 <?= h(format_task_no($taskId)) ?> · 为每行填写 UPC Code</p></div><a class="btn btn-secondary" href="<?= h(app_url('contract_form_view.php?id=' . $taskId)) ?>">返回任务</a></div>
<section class="card"><div class="card-head"><h3>物料匹配</h3><span class="status-badge <?= h(status_class($task['status'])) ?>"><?= h(status_label($task['status'])) ?></span></div><div class="card-body">
<?php if ((int)$task['status'] !== 4): ?><div class="alert success"><span>✓</span>该任务当前不可提交匹配，状态为“<?= h(status_label($task['status'])) ?>”。</div><?php endif; ?>
<form id="mock-form"><input type="hidden" name="intellisight_id" value="<?= h(format_task_no($taskId)) ?>"><div class="table-wrap"><table class="data-table result-table"><thead><tr><th>Vendor Part No.</th><th>Description</th><th>UPC Code</th></tr></thead><tbody>
<?php for ($i=0; $i<$rowCount; $i++): ?><tr><td><?= h($vendors[$i] ?? '') ?></td><td><?= h($descriptions[$i] ?? '') ?></td><td><input class="form-control" name="upc_code[]" value="<?= h($upcs[$i] ?? '') ?>" required placeholder="请输入 UPC Code"></td></tr><?php endfor; ?>
</tbody></table></div><div style="margin-top:20px;text-align:right"><button class="btn btn-primary" type="submit" <?= (int)$task['status'] !== 4 ? 'disabled' : '' ?>>模拟匹配并返回</button></div><div id="mock-message" style="margin-top:14px"></div></form>
</div></section>
<script>
document.getElementById('mock-form').addEventListener('submit', async function (event) {
    event.preventDefault();
    const message = document.getElementById('mock-message');
    const form = new FormData(this);
    const payload = {data: {intellisight_id: form.get('intellisight_id'), upc_code: form.getAll('upc_code[]')}};
    try {
        const response = await fetch('<?= h(app_url('api/p_sys_back.php')) ?>', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
        const result = await response.json();
        message.className = 'alert ' + (result.success ? 'success' : 'error');
        message.textContent = result.message;
        if (result.success) setTimeout(function(){ location.href='<?= h(app_url('contract_form_view.php?id=' . $taskId)) ?>'; }, 700);
    } catch (error) { message.className='alert error'; message.textContent='提交失败，请稍后重试。'; }
});
</script>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
