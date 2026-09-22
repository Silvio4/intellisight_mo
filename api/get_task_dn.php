<?php
require __DIR__ . '/../includes/bootstrap.php';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET','POST'], true)) json_response(['success'=>false,'message'=>'仅支持 GET 或 POST。'], 405);

$pdo = db();
try {
    $pdo->beginTransaction();
    $busy = $pdo->query('SELECT id FROM dn_task_pool WHERE status=2 ORDER BY id LIMIT 1 FOR UPDATE')->fetch();
    if ($busy) {
        $pdo->commit();
        json_response(['success'=>true,'has_task'=>false,'message'=>'已有识别中 DN 任务，暂不派发新任务','recognizing_dn_task_id'=>(int)$busy['id']]);
    }
    $task = $pdo->query('SELECT id,order_task_id,sequence_no,upload_file_name,original_file_name,uploaded_at FROM dn_task_pool WHERE status=1 ORDER BY id LIMIT 1 FOR UPDATE')->fetch();
    if (!$task) {
        $pdo->commit();
        json_response(['success'=>true,'has_task'=>false,'message'=>'暂无待识别 DN 任务']);
    }
    $update = $pdo->prepare('UPDATE dn_task_pool SET status=2,recognition_started_at=COALESCE(recognition_started_at,NOW()),updated_at=NOW() WHERE id=:id AND status=1');
    $update->execute([':id'=>(int)$task['id']]);
    if ($update->rowCount() !== 1) throw new RuntimeException('DN 任务状态发生变化，请重试。');
    $pdo->commit();
    $dnId = (int)$task['id'];
    json_response(['success'=>true,'has_task'=>true,'dn_task_id'=>$dnId,'task'=>[
        'dn_task_id'=>$dnId,
        'order_task_id'=>(int)$task['order_task_id'],
        'order_task_no'=>format_task_no($task['order_task_id']),
        'sequence_no'=>(int)$task['sequence_no'],
        'status'=>2,
        'status_text'=>'识别中',
        'uploaded_at'=>$task['uploaded_at'],
        'original_file_name'=>$task['original_file_name'],
        'dn_file'=>$task['upload_file_name'],
        'dn_file_url'=>absolute_app_url('api/download_dn.php?id='.$dnId),
    ]]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    write_app_log('api', '获取DN任务失败', ['error'=>$e->getMessage()]);
    json_response(['success'=>false,'message'=>'获取待识别 DN 任务失败'], 500);
}
