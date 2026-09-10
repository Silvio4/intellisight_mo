<?php
require __DIR__ . '/includes/auth.php';

$pageTitle = '合同表单';
$pdo = db();

$summary = $pdo->query("SELECT COUNT(*) AS total, SUM(status='pending') AS pending, SUM(status='recognizing') AS recognizing, SUM(status='completed') AS completed FROM contract_tasks")->fetch();
$summary = [
    'total' => (int)($summary['total'] ?? 0),
    'pending' => (int)($summary['pending'] ?? 0),
    'recognizing' => (int)($summary['recognizing'] ?? 0),
    'completed' => (int)($summary['completed'] ?? 0),
];

$taskNo = trim((string)($_GET['task_no'] ?? ''));
$salesPerson = trim((string)($_GET['sales_person'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$allowedStatuses = ['pending', 'recognizing', 'completed', 'failed'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$where = [];
$params = [];
if ($taskNo !== '') {
    $where[] = 't.task_no LIKE :task_no';
    $params[':task_no'] = '%' . $taskNo . '%';
}
if ($salesPerson !== '') {
    $where[] = 't.sales_person LIKE :sales_person';
    $params[':sales_person'] = '%' . $salesPerson . '%';
}
if ($status !== '') {
    $where[] = 't.status = :status';
    $params[':status'] = $status;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 15;
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM contract_tasks t' . $whereSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;

$stmt = $pdo->prepare(
    'SELECT t.id, t.task_no, t.sales_person, t.status, t.created_at, t.claimed_at, t.completed_at,
            u.name AS creator_name, u.username AS creator_username,
            COUNT(f.id) AS file_count
       FROM contract_tasks t
       LEFT JOIN users u ON u.id = t.created_by
       LEFT JOIN contract_task_files f ON f.task_id = t.id'
    . $whereSql .
    ' GROUP BY t.id, t.task_no, t.sales_person, t.status, t.created_at, t.claimed_at, t.completed_at, u.name, u.username
      ORDER BY t.id DESC LIMIT :limit OFFSET :offset'
);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$tasks = $stmt->fetchAll();

function list_url(int $targetPage): string
{
    $query = $_GET;
    $query['page'] = $targetPage;
    return app_url('contract_forms.php?' . http_build_query($query));
}

require __DIR__ . '/includes/layout_top.php';
?>
<div class="page-head">
    <div><h2>合同表单</h2><p>创建并跟踪澳门合同资料识别任务</p></div>
    <a class="btn btn-primary" href="<?= h(app_url('contract_form_create.php')) ?>"><span>＋</span> 新建表单</a>
</div>

<div class="summary-grid">
    <div class="card summary-card"><span class="summary-icon all">▦</span><div><b><?= $summary['total'] ?></b><span>全部任务</span></div></div>
    <div class="card summary-card"><span class="summary-icon pending">◷</span><div><b><?= $summary['pending'] ?></b><span>待识别</span></div></div>
    <div class="card summary-card"><span class="summary-icon recognizing">◎</span><div><b><?= $summary['recognizing'] ?></b><span>识别中</span></div></div>
    <div class="card summary-card"><span class="summary-icon completed">✓</span><div><b><?= $summary['completed'] ?></b><span>已完成</span></div></div>
</div>

<div class="card" style="margin-bottom:18px">
    <form class="filter-bar" method="get">
        <div class="filter-item"><label for="task_no">任务号</label><input class="form-control" id="task_no" name="task_no" value="<?= h($taskNo) ?>" placeholder="例如 T000001"></div>
        <div class="filter-item"><label for="sales_person">Sales Person</label><input class="form-control" id="sales_person" name="sales_person" value="<?= h($salesPerson) ?>" placeholder="输入销售人员"></div>
        <div class="filter-item"><label for="status">任务状态</label><select class="form-control" id="status" name="status"><option value="">全部状态</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= h($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= h(status_label($option)) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary" type="submit">查询</button>
        <a class="btn btn-secondary" href="<?= h(app_url('contract_forms.php')) ?>">重置</a>
    </form>
</div>

<div class="card">
    <div class="card-head"><h3>任务列表</h3><span style="color:#8b95a6">共 <?= $totalRows ?> 条</span></div>
    <?php if (!$tasks): ?>
        <div class="empty-state"><div class="empty-icon">▤</div><strong>暂时没有合同任务</strong><p>点击右上角“新建表单”提交第一条任务。</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>任务号</th><th>Sales Person</th><th>文件数量</th><th>创建人</th><th>创建时间</th><th>状态</th><th>完成时间</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($tasks as $task): ?>
                    <tr>
                        <td><a class="task-link" href="<?= h(app_url('contract_form_detail.php?task_no=' . rawurlencode($task['task_no']))) ?>"><?= h($task['task_no']) ?></a></td>
                        <td><?= h($task['sales_person']) ?></td>
                        <td><?= (int)$task['file_count'] ?> 份</td>
                        <td><?= h($task['creator_name'] ?: $task['creator_username']) ?></td>
                        <td><?= h($task['created_at']) ?></td>
                        <td><span class="status-badge <?= h(status_class($task['status'])) ?>"><?= h(status_label($task['status'])) ?></span></td>
                        <td><?= h($task['completed_at'] ?: '—') ?></td>
                        <td><a class="btn btn-secondary btn-sm" href="<?= h(app_url('contract_form_detail.php?task_no=' . rawurlencode($task['task_no']))) ?>">查看详情</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination">
            <span>第 <?= $page ?> / <?= $totalPages ?> 页</span>
            <div class="pagination-links">
                <?php if ($page > 1): ?><a href="<?= h(list_url($page - 1)) ?>">‹</a><?php endif; ?>
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?><a class="<?= $i === $page ? 'active' : '' ?>" href="<?= h(list_url($i)) ?>"><?= $i ?></a><?php endfor; ?>
                <?php if ($page < $totalPages): ?><a href="<?= h(list_url($page + 1)) ?>">›</a><?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
