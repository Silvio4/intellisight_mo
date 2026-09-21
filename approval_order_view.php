<?php
require __DIR__ . '/includes/auth.php';
require_approver();
require_once __DIR__ . '/includes/eportal.php';
$id=(int)($_GET['id']??$_POST['id']??0);$pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$action=(string)($_POST['action']??'');
    if($action==='reject'){
        $reason=trim((string)($_POST['reason']??''));
        if((function_exists('mb_strlen')?mb_strlen($reason,'UTF-8'):strlen($reason))<5){flash('error','退回理由至少填写 5 个字。');redirect('approval_order_view.php?id='.$id);}
        $pdo->beginTransaction();
        $stmt=$pdo->prepare('UPDATE contract_forms SET status=7,rejected_at=NOW(),rejected_by=:uid,rejection_reason=:reason,updated_at=NOW() WHERE id=:id AND status=6');
        $stmt->execute([':uid'=>(int)$_SESSION['user_id'],':reason'=>$reason,':id'=>$id]);
        if($stmt->rowCount()!==1){$pdo->rollBack();flash('error','订单状态已变化，请刷新后重试。');redirect('approval_order_view.php?id='.$id);}
        $pdo->prepare("INSERT INTO contract_approvals(task_id,approval_round,action,operator_id,operator_name,reason,created_at) SELECT id,approval_round,'reject',:uid,:name,:reason,NOW() FROM contract_forms WHERE id=:id")->execute([':uid'=>(int)$_SESSION['user_id'],':name'=>(string)$_SESSION['name'],':reason'=>$reason,':id'=>$id]);
        $pdo->prepare("INSERT INTO logs(operator,task_id,operation_time,operation_content,task_status) VALUES(:name,:id,NOW(),'审批退回',7)")->execute([':name'=>(string)$_SESSION['name'],':id'=>$id]);$pdo->commit();
        flash('success','订单已退回。');redirect('approval_orders.php?status=6');
    }
    if(in_array($action,['approve','retry'],true)){
        $from=$action==='approve'?6:10;$key=format_task_no($id).'-'.bin2hex(random_bytes(12));
        $pdo->beginTransaction();
        $stmt=$pdo->prepare('UPDATE contract_forms SET status=8,approved_at=IF(:approve_status=6,NOW(),approved_at),approved_by=IF(:approve_status2=6,:uid,approved_by),eportal_submitted_at=NOW(),eportal_request_key=:request_key,eportal_last_error=NULL,updated_at=NOW() WHERE id=:id AND status=:expected_status');
        $stmt->execute([':approve_status'=>$from,':approve_status2'=>$from,':uid'=>(int)$_SESSION['user_id'],':request_key'=>$key,':id'=>$id,':expected_status'=>$from]);
        if($stmt->rowCount()!==1){$pdo->rollBack();flash('error','订单状态已变化，请刷新后重试。');redirect('approval_order_view.php?id='.$id);}
        $logAction=$action==='approve'?'approve':'eportal_retry';
        $pdo->prepare('INSERT INTO contract_approvals(task_id,approval_round,action,operator_id,operator_name,created_at) SELECT id,approval_round,:action,:uid,:name,NOW() FROM contract_forms WHERE id=:id')->execute([':action'=>$logAction,':uid'=>(int)$_SESSION['user_id'],':name'=>(string)$_SESSION['name'],':id'=>$id]);$pdo->commit();
        try{$result=submit_task_to_eportal($id);$pdo->prepare('UPDATE contract_forms SET status=9,eportal_completed_at=NOW(),completed_at=NOW(),eportal_ticket_no=:ticket,eportal_response=:response,updated_at=NOW() WHERE id=:id AND status=8')->execute([':ticket'=>$result['ticket_no'],':response'=>$result['raw'],':id'=>$id]);$pdo->prepare("INSERT INTO logs(operator,task_id,operation_time,operation_content,task_status) VALUES('API:eportal',:id,NOW(),'ePortal建单成功',9)")->execute([':id'=>$id]);flash('success','审批通过，ePortal 已成功建单。');}
        catch(Throwable $e){$pdo->prepare('UPDATE contract_forms SET status=10,eportal_last_error=:error,updated_at=NOW() WHERE id=:id AND status=8')->execute([':error'=>$e->getMessage(),':id'=>$id]);write_app_log('eportal','建单失败',['task_id'=>$id,'error'=>$e->getMessage()]);flash('error','审批已通过，但 ePortal 建单失败：'.$e->getMessage());}
        redirect('approval_order_view.php?id='.$id);
    }
}
$stmt=$pdo->prepare('SELECT * FROM contract_forms WHERE id=:id');$stmt->execute([':id'=>$id]);$task=$stmt->fetch();if(!$task){http_response_code(404);exit('任务不存在');}
$history=$pdo->prepare('SELECT * FROM contract_approvals WHERE task_id=:id ORDER BY id DESC');$history->execute([':id'=>$id]);$history=$history->fetchAll();
$pageTitle='审批详情';require __DIR__ . '/includes/layout_top.php';
?>
<div class="page-head"><div><h2><?=h(format_task_no($id))?></h2><p>订单审批与 ePortal 建单</p></div><div><a class="btn btn-secondary" href="<?=h(app_url('contract_form_view.php?id='.$id))?>">查看完整资料</a> <a class="btn btn-secondary" href="<?=h(app_url('approval_orders.php'))?>">返回列表</a></div></div>
<section class="card"><div class="card-head"><h3>审批摘要</h3><span class="status-badge <?=h(status_class($task['status']))?>"><?=h(status_label($task['status']))?></span></div><div class="card-body"><div class="info-grid"><div class="info-item"><label>客户</label><div><?=h($task['customer_name']?:'—')?></div></div><div class="info-item"><label>PO No.</label><div><?=h($task['po_no']?:'—')?></div></div><div class="info-item"><label>Sales Person</label><div><?=h($task['sales_person'])?></div></div><div class="info-item"><label>创建人</label><div><?=h($task['created_by_name'])?></div></div><?php if($task['rejection_reason']):?><div class="info-item full"><label>退回理由</label><div class="danger-text"><?=h($task['rejection_reason'])?></div></div><?php endif;?><?php if($task['eportal_last_error']):?><div class="info-item full"><label>建单错误</label><div class="danger-text"><?=h($task['eportal_last_error'])?></div></div><?php endif;?></div>
<?php if((int)$task['status']===6):?><div class="approval-actions"><form method="post" onsubmit="return confirm('确认同意并提交 ePortal 建单？')"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><button class="btn btn-primary" name="action" value="approve">同意并建单</button></form><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><textarea class="form-control" name="reason" minlength="5" maxlength="1000" required placeholder="请填写退回理由（至少5个字）"></textarea><button class="btn btn-danger" name="action" value="reject">退回订单</button></form></div><?php elseif((int)$task['status']===10):?><form method="post" onsubmit="return confirm('请先确认 ePortal 未产生重复订单。确定重试？')"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><button class="btn btn-primary" name="action" value="retry">重试建单</button></form><?php endif;?></div></section>
<section class="card" style="margin-top:20px"><div class="card-head"><h3>审批记录</h3></div><div class="table-wrap"><table class="data-table"><thead><tr><th>轮次</th><th>动作</th><th>操作人</th><th>理由</th><th>时间</th></tr></thead><tbody><?php foreach($history as $row):?><tr><td><?=$row['approval_round']?></td><td><?=h($row['action'])?></td><td><?=h($row['operator_name'])?></td><td><?=h($row['reason']?:'—')?></td><td><?=h($row['created_at'])?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php require __DIR__ . '/includes/layout_bottom.php';?>
