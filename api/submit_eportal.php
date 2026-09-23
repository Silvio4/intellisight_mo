<?php
require __DIR__ . '/../includes/auth.php';
require_approver();
require_once __DIR__ . '/../includes/eportal.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success'=>false, 'message'=>'仅支持 POST。'], 405);
}

$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$isFormRequest = stripos($contentType, 'application/json') === false;
$payload = request_payload();
$givenToken = (string)($payload['csrf_token'] ?? '');
if ($givenToken === '' || !hash_equals(csrf_token(), $givenToken)) {
    if ($isFormRequest) {
        http_response_code(419);
        exit('页面已过期，请返回刷新后重试。');
    }
    json_response(['success'=>false, 'message'=>'CSRF token 无效。'], 419);
}

$taskId = parse_task_id($payload['task_id'] ?? $payload['id'] ?? 0);
$action = (string)($payload['action'] ?? '');
if ($taskId < 1 || !in_array($action, ['approve', 'retry'], true)) {
    json_response(['success'=>false, 'message'=>'缺少有效的 task_id 或 action。'], 422);
}

$pdo = db();
try {
    $from = $action === 'approve' ? 6 : 10;
    $key = format_task_no($taskId) . '-' . bin2hex(random_bytes(12));
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('UPDATE contract_forms SET status=8,approved_at=IF(:approve_status=6,NOW(),approved_at),approved_by=IF(:approve_status2=6,:uid,approved_by),eportal_submitted_at=NOW(),eportal_request_key=:request_key,eportal_last_error=NULL,updated_at=NOW() WHERE id=:id AND status=:expected_status');
    $stmt->execute([
        ':approve_status'=>$from, ':approve_status2'=>$from,
        ':uid'=>(int)$_SESSION['user_id'], ':request_key'=>$key,
        ':id'=>$taskId, ':expected_status'=>$from,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('订单状态已变化，请刷新后重试。');
    }
    $logAction = $action === 'approve' ? 'approve' : 'eportal_retry';
    $pdo->prepare('INSERT INTO contract_approvals(task_id,approval_round,action,operator_id,operator_name,created_at) SELECT id,approval_round,:action,:uid,:name,NOW() FROM contract_forms WHERE id=:id')
        ->execute([':action'=>$logAction, ':uid'=>(int)$_SESSION['user_id'], ':name'=>(string)$_SESSION['name'], ':id'=>$taskId]);
    $pdo->commit();

    $result = submit_task_to_eportal($taskId);
    $pdo->prepare('UPDATE contract_forms SET status=9,eportal_completed_at=NOW(),completed_at=NOW(),eportal_ticket_no=:ticket,eportal_response=:response,updated_at=NOW() WHERE id=:id AND status=8')
        ->execute([':ticket'=>$result['ticket_no'], ':response'=>$result['raw'], ':id'=>$taskId]);
    $pdo->prepare("INSERT INTO logs(operator,task_id,operation_time,operation_content,task_status) VALUES('API:submit_eportal',:id,NOW(),'ePortal建单成功',9)")
        ->execute([':id'=>$taskId]);

    if ($isFormRequest) {
        flash('success', '审批通过，ePortal 已成功建单。');
        redirect('approval_order_view.php?id=' . $taskId);
    }
    json_response(['success'=>true, 'message'=>'ePortal 已成功建单。', 'task_id'=>$taskId, 'ticket_no'=>$result['ticket_no'], 'eportal_response'=>$result['response']]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->prepare('UPDATE contract_forms SET status=10,eportal_last_error=:error,updated_at=NOW() WHERE id=:id AND status=8')
        ->execute([':error'=>$e->getMessage(), ':id'=>$taskId]);
    write_app_log('eportal', '建单失败', ['task_id'=>$taskId, 'error'=>$e->getMessage()]);
    if ($isFormRequest) {
        flash('error', '审批已通过，但 ePortal 建单失败：' . $e->getMessage());
        redirect('approval_order_view.php?id=' . $taskId);
    }
    json_response(['success'=>false, 'message'=>$e->getMessage(), 'task_id'=>$taskId], 409);
}
