<?php
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/p_system.php';
$taskId = parse_task_id($_GET['id'] ?? 0);
if ($taskId < 1) { http_response_code(422); exit('缺少任务号'); }
$stmt = db()->prepare('SELECT id,status,vendor_part_no,description,pid,p_sys_link FROM contract_forms WHERE id=:id LIMIT 1');
$stmt->execute([':id' => $taskId]);
$task = $stmt->fetch();
if (!$task) { http_response_code(404); exit('任务不存在'); }
$fullStmt = db()->prepare('SELECT * FROM contract_forms WHERE id=:id LIMIT 1');
$fullStmt->execute([':id' => $taskId]);
$pRequestData = p_system_payload($fullStmt->fetch());
$vendors = split_result_values($task['vendor_part_no']);
$descriptions = split_result_values($task['description']);
$pids = split_result_values($task['pid']);
$rowCount = max(count($vendors), count($descriptions));
$pageTitle = '模拟 P 系统';
require __DIR__ . '/includes/layout_top.php';
?>
<div class="page-head"><div><h2>模拟 P 系统匹配</h2><p>任务 <?= h(format_task_no($taskId)) ?> · 为每行填写 PID</p></div><a class="btn btn-secondary" href="<?= h(app_url('contract_form_view.php?id=' . $taskId)) ?>">返回任务</a></div>
<section class="card"><div class="card-head"><h3>物料匹配</h3><span class="status-badge <?= h(status_class($task['status'])) ?>"><?= h(status_label($task['status'])) ?></span></div><div class="card-body">
<?php if ((int)$task['status'] !== 4): ?><div class="alert success"><span>✓</span>该任务当前不可提交匹配，状态为“<?= h(status_label($task['status'])) ?>”。</div><?php endif; ?>
<form id="mock-form"><input type="hidden" name="task_id" value="<?= (int)$taskId ?>"><div class="table-wrap"><table class="data-table result-table"><thead><tr><th>Vendor Part No.</th><th>Description</th><th>PID</th></tr></thead><tbody>
<?php for ($i=0; $i<$rowCount; $i++): ?><tr><td><?= h($vendors[$i] ?? '') ?></td><td><?= h($descriptions[$i] ?? '') ?></td><td><input class="form-control" name="pid[]" value="<?= h($pids[$i] ?? '') ?>" required placeholder="请输入 PID"></td></tr><?php endfor; ?>
</tbody></table></div><div class="form-group" style="margin-top:20px"><label>P System Link</label><input class="form-control" type="url" name="p_sys_link" value="<?= h($task['p_sys_link'] ?? '') ?>" required placeholder="https://p-system.example/task/..."></div><div style="margin-top:20px;text-align:right"><button class="btn btn-primary" type="submit" <?= (int)$task['status'] !== 4 ? 'disabled' : '' ?>>模拟匹配并返回</button></div><div id="mock-message" style="margin-top:14px"></div></form>
</div></section>
<script>
document.getElementById('mock-form').addEventListener('submit', async function (event) {
    event.preventDefault();
    const message = document.getElementById('mock-message');
    const form = new FormData(this);
    const data = <?= json_encode($pRequestData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    data.pid = form.getAll('pid[]').join(';');
    data.p_sys_link = form.get('p_sys_link');
    const payload = {data};
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
