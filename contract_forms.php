<?php
require __DIR__ . '/includes/auth.php';

$pageTitle = '合同表单';
$pdo = db();
$s = $pdo->query('SELECT COUNT(*) total, SUM(status=2) pending, SUM(status IN (3,4)) processing, SUM(status=6) completed FROM contract_forms')->fetch();
$summary = [
    'total' => (int)($s['total'] ?? 0),
    'pending' => (int)($s['pending'] ?? 0),
    'processing' => (int)($s['processing'] ?? 0),
    'completed' => (int)($s['completed'] ?? 0),
];

$taskId = trim((string)($_GET['id'] ?? ''));
$creator = trim((string)($_GET['creator'] ?? ''));
$status = (int)($_GET['status'] ?? 0);
if ($status < 1 || $status > 6) $status = 0;

$where = [];
$params = [];
if ($taskId !== '' && parse_task_id($taskId) > 0) {
    $where[] = 'id=:id';
    $params[':id'] = parse_task_id($taskId);
}
if ($creator !== '') {
    $where[] = 'created_by_name LIKE :creator';
    $params[':creator'] = '%' . $creator . '%';
}
if ($status) {
    $where[] = 'status=:status';
    $params[':status'] = $status;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$page = max(1, (int)($_GET['page'] ?? 1));
$pageSize = 15;
$count = $pdo->prepare('SELECT COUNT(*) FROM contract_forms' . $whereSql);
$count->execute($params);
$totalRows = (int)$count->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $pageSize));
$page = min($page, $totalPages);
$stmt = $pdo->prepare('SELECT * FROM contract_forms' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset');
foreach ($params as $key => $value) $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
$stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
$stmt->execute();
$tasks = $stmt->fetchAll();

function list_url(int $page): string
{
    $query = $_GET;
    $query['page'] = $page;
    return app_url('contract_forms.php?' . http_build_query($query));
}

function filter_reset_url(string $field): string
{
    $query = $_GET;
    unset($query[$field], $query['page']);
    $suffix = http_build_query($query);
    return app_url('contract_forms.php' . ($suffix === '' ? '' : '?' . $suffix));
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
    <div class="card summary-card"><span class="summary-icon recognizing">◎</span><div><b><?= $summary['processing'] ?></b><span>处理中</span></div></div>
    <div class="card summary-card"><span class="summary-icon completed">✓</span><div><b><?= $summary['completed'] ?></b><span>已完成</span></div></div>
</div>
<div class="card">
    <div class="card-head"><h3>任务列表</h3><span style="color:#8b95a6">共 <?= $totalRows ?> 条</span></div>
    <?php if (!$tasks): ?>
        <div class="empty-state"><div class="empty-icon">▤</div><strong>暂时没有合同任务</strong></div>
    <?php else: ?>
        <div class="table-wrap filter-table-wrap">
            <table class="data-table">
                <thead><tr>
                    <th>
                        <details class="column-filter" <?= $taskId !== '' ? 'data-active' : '' ?>>
                            <summary>任务号 <span class="filter-caret">▾</span></summary>
                            <form class="column-filter-panel" method="get">
                                <?php if ($creator !== ''): ?><input type="hidden" name="creator" value="<?= h($creator) ?>"><?php endif; ?>
                                <?php if ($status): ?><input type="hidden" name="status" value="<?= $status ?>"><?php endif; ?>
                                <label for="filter-id">任务号</label>
                                <input class="form-control" id="filter-id" name="id" value="<?= h($taskId) ?>" placeholder="例如 T000001">
                                <div class="column-filter-actions"><a href="<?= h(filter_reset_url('id')) ?>">清除</a><button class="btn btn-primary btn-sm">确认</button></div>
                            </form>
                        </details>
                    </th>
                    <th>Sales Person</th><th>文件</th>
                    <th>
                        <details class="column-filter" <?= $creator !== '' ? 'data-active' : '' ?>>
                            <summary>创建人 <span class="filter-caret">▾</span></summary>
                            <form class="column-filter-panel" method="get">
                                <?php if ($taskId !== ''): ?><input type="hidden" name="id" value="<?= h($taskId) ?>"><?php endif; ?>
                                <?php if ($status): ?><input type="hidden" name="status" value="<?= $status ?>"><?php endif; ?>
                                <label for="filter-creator">创建人</label>
                                <input class="form-control" id="filter-creator" name="creator" value="<?= h($creator) ?>" placeholder="输入姓名">
                                <div class="column-filter-actions"><a href="<?= h(filter_reset_url('creator')) ?>">清除</a><button class="btn btn-primary btn-sm">确认</button></div>
                            </form>
                        </details>
                    </th>
                    <th>创建时间</th>
                    <th>
                        <details class="column-filter" <?= $status ? 'data-active' : '' ?>>
                            <summary>状态 <span class="filter-caret">▾</span></summary>
                            <form class="column-filter-panel" method="get">
                                <?php if ($taskId !== ''): ?><input type="hidden" name="id" value="<?= h($taskId) ?>"><?php endif; ?>
                                <?php if ($creator !== ''): ?><input type="hidden" name="creator" value="<?= h($creator) ?>"><?php endif; ?>
                                <label for="filter-status">任务状态</label>
                                <select class="form-control" id="filter-status" name="status">
                                    <option value="">全部状态</option>
                                    <?php for ($i = 1; $i <= 6; $i++): ?><option value="<?= $i ?>" <?= $status === $i ? 'selected' : '' ?>><?= h(status_label($i)) ?></option><?php endfor; ?>
                                </select>
                                <div class="column-filter-actions"><a href="<?= h(filter_reset_url('status')) ?>">清除</a><button class="btn btn-primary btn-sm">确认</button></div>
                            </form>
                        </details>
                    </th>
                    <th>完成时间</th><th>操作</th>
                </tr></thead>
                <tbody><?php foreach ($tasks as $task): ?><tr>
                    <td><a class="task-link" href="<?= h(app_url('contract_form_view.php?id=' . (int)$task['id'])) ?>"><?= h(format_task_no($task['id'])) ?></a></td>
                    <td><?= h($task['sales_person'] ?: '—') ?></td><td><?= h($task['attachment_original_name'] ?: '—') ?></td><td><?= h($task['created_by_name']) ?></td><td><?= h($task['created_at']) ?></td>
                    <td><span class="status-badge <?= h(status_class($task['status'])) ?>"><?= h(status_label($task['status'])) ?></span></td>
                    <td><?= h($task['completed_at'] ?: '—') ?></td><td><a class="btn btn-secondary btn-sm" href="<?= h(app_url('contract_form_view.php?id=' . (int)$task['id'])) ?>">查看详情</a></td>
                </tr><?php endforeach; ?></tbody>
            </table>
        </div>
        <div class="pagination"><span>第 <?= $page ?> / <?= $totalPages ?> 页</span><div class="pagination-links"><?php if ($page > 1): ?><a href="<?= h(list_url($page - 1)) ?>">‹</a><?php endif; ?><?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?><a class="<?= $i === $page ? 'active' : '' ?>" href="<?= h(list_url($i)) ?>"><?= $i ?></a><?php endfor; ?><?php if ($page < $totalPages): ?><a href="<?= h(list_url($page + 1)) ?>">›</a><?php endif; ?></div></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
