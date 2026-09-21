<?php
require __DIR__ . '/includes/auth.php';
require_approver();
$pageTitle='审批订单';
$status=(int)($_GET['status']??6);
if(!in_array($status,[6,7,9,10],true))$status=6;
$stmt=db()->prepare('SELECT * FROM contract_forms WHERE status=:status ORDER BY COALESCE(submitted_approval_at,updated_at) ASC,id ASC');
$stmt->execute([':status'=>$status]);$tasks=$stmt->fetchAll();
require __DIR__ . '/includes/layout_top.php';
?>
<div class="page-head"><div><h2>审批订单</h2><p>审核 Costing Sheet，并在通过后提交 ePortal 建单</p></div></div>
<div class="card"><div class="card-head"><h3><?=h(status_label($status))?></h3><form method="get"><select class="form-control" name="status" onchange="this.form.submit()"><?php foreach([6,7,9,10] as $value):?><option value="<?=$value?>" <?=$status===$value?'selected':''?>><?=h(status_label($value))?></option><?php endforeach;?></select></form></div>
<?php if(!$tasks):?><div class="empty-state"><div class="empty-icon">✓</div><strong>当前没有<?=h(status_label($status))?>订单</strong></div><?php else:?><div class="table-wrap"><table class="data-table"><thead><tr><th>任务号</th><th>客户</th><th>PO No.</th><th>Sales Person</th><th>创建人</th><th>提交审批时间</th><th>状态</th><th>操作</th></tr></thead><tbody><?php foreach($tasks as $task):?><tr><td><?=h(format_task_no($task['id']))?></td><td><?=h($task['customer_name']?:'—')?></td><td><?=h($task['po_no']?:'—')?></td><td><?=h($task['sales_person'])?></td><td><?=h($task['created_by_name'])?></td><td><?=h($task['submitted_approval_at']?:'—')?></td><td><span class="status-badge <?=h(status_class($task['status']))?>"><?=h(status_label($task['status']))?></span></td><td><a class="btn btn-primary btn-sm" href="<?=h(app_url('approval_order_view.php?id='.(int)$task['id']))?>">审批详情</a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div>
<?php require __DIR__ . '/includes/layout_bottom.php';?>
