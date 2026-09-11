<?php
require __DIR__ . '/includes/auth.php';

$taskId = (int)($_GET['id'] ?? 0);
if ($taskId < 1) { redirect('contract_forms.php'); }
$stmt = db()->prepare('SELECT * FROM contract_forms WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $taskId]);
$task = $stmt->fetch();
if (!$task) { http_response_code(404); exit('任务不存在'); }
$result = ($task['po_no'] !== '' || $task['recognition_finished_at'] !== null) ? $task : null;
$files = $task['attachment_contract_quote_epo'] ? [$task] : [];

function split_result_value($value): array
{
    if ($value === null || trim((string)$value) === '') return [];
    return array_map('trim', explode(';', (string)$value));
}
$lineItems = [];
if ($result) {
    $columns = [];
    foreach (['no','vendor_part_no','description','qty','unit_cost'] as $field) $columns[$field] = split_result_value($result[$field]);
    $columnCounts = array_map('count', $columns);
    $rowCount = $columnCounts ? max($columnCounts) : 0;
    for ($i=0;$i<$rowCount;$i++) $lineItems[]=['no'=>$columns['no'][$i]??'','vendor_part_no'=>$columns['vendor_part_no'][$i]??'','description'=>$columns['description'][$i]??'','qty'=>$columns['qty'][$i]??'','unit_cost'=>$columns['unit_cost'][$i]??''];
}

$pageTitle = '任务详情';
require __DIR__ . '/includes/layout_top.php';
?>
<div class="page-head">
    <div><h2><?= h($task['id']) ?></h2><p>合同任务详情与识别结果</p></div>
    <a class="btn btn-secondary" href="<?= h(app_url('contract_forms.php')) ?>">← 返回列表</a>
</div>

<div class="detail-grid">
    <section class="card">
        <div class="card-head"><h3>任务信息</h3><span class="status-badge <?= h(status_class($task['status'])) ?>"><?= h(status_label($task['status'])) ?></span></div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><label>任务号</label><div><b><?= h($task['id']) ?></b></div></div>
                <div class="info-item"><label>Sales Person</label><div><?= h($task['sales_person'] ?: '—') ?></div></div>
                <div class="info-item"><label>创建人</label><div><?= h($task['created_by_name']) ?></div></div>
                <div class="info-item"><label>创建时间</label><div><?= h($task['created_at']) ?></div></div>
                <div class="info-item"><label>开始识别时间</label><div><?= h($task['recognition_started_at'] ?: '—') ?></div></div>
                <div class="info-item"><label>完成时间</label><div><?= h($task['completed_at'] ?: '—') ?></div></div>
                <?php if (!empty($task['error_message'])): ?><div class="info-item full"><label>错误信息</label><div style="color:#c43f50"><?= h($task['error_message']) ?></div></div><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-head"><h3>任务文件</h3><span style="color:#929bab"><?= count($files) ?> 份</span></div>
        <div class="card-body">
            <?php foreach ($files as $file): ?>
                <div class="file-download">
                    <span class="file-type"><?= h(strtoupper(substr($file['attachment_extension'], 0, 4))) ?></span>
                    <span class="file-meta"><b><?= h($file['attachment_original_name']) ?></b><small><?= number_format(((int)$file['attachment_file_size']) / 1024, 1) ?> KB · 已生成 PDF</small></span>
                    <a class="btn btn-secondary btn-sm" href="<?= h(app_url('download.php?id=' . (int)$file['id'] . '&type=source')) ?>">原文件</a>
                    <a class="btn btn-primary btn-sm" href="<?= h(app_url('download.php?id=' . (int)$file['id'] . '&type=pdf')) ?>">PDF</a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<section class="card" style="margin-top:20px">
    <div class="card-head"><h3>识别结果</h3><?php if (!$result): ?><span style="color:#929bab">等待接口回传</span><?php endif; ?></div>
    <div class="card-body">
        <?php if (!$result): ?>
            <div class="empty-state" style="padding:35px 20px"><div class="empty-icon">◎</div><strong><?= (int)$task['status'] === 2 ? '任务正在等待领取' : '暂未收到识别结果' ?></strong><p>识别系统调用接口并返回结果后，此处会自动展示。</p></div>
        <?php else: ?>
            <div class="info-grid" style="margin-bottom:20px">
                <div class="info-item"><label>PO No.</label><div><?= h($result['po_no'] ?: '—') ?></div></div>
                <div class="info-item"><label>Discount</label><div><?= h($result['discount'] !== null && $result['discount'] !== '' ? $result['discount'] : '—') ?></div></div>
                <div class="info-item full"><label>Delivery Address</label><div><?= nl2br(h($result['delivery_address'] ?: '—')) ?></div></div>
            </div>
            <div class="table-wrap">
                <table class="data-table result-table">
                    <thead><tr><th>No.</th><th>Vendor Part No.</th><th>Description</th><th>Qty</th><th>Unit Cost</th></tr></thead>
                    <tbody>
                    <?php if (!$lineItems): ?><tr><td colspan="5" style="text-align:center;color:#929bab">接口未返回明细行</td></tr><?php endif; ?>
                    <?php foreach ($lineItems as $item): ?><tr><td><?= h($item['no']) ?></td><td><?= h($item['vendor_part_no']) ?></td><td><?= h($item['description']) ?></td><td><?= h($item['qty']) ?></td><td><?= h($item['unit_cost']) ?></td></tr><?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php if ($result && !empty($task['raw_result'])): ?>
<section class="card" style="margin-top:20px">
    <div class="card-head"><h3>接口原始数据</h3><span style="color:#929bab">用于排查和追溯</span></div>
    <div class="card-body"><pre class="raw-json"><?= h(json_encode(json_decode($task['raw_result'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
