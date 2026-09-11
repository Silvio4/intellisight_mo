<?php
require __DIR__ . '/../includes/bootstrap.php';
$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','POST'],true)) json_response(['success'=>false,'message'=>'仅支持 GET 或 POST。'],405);

function file_download_url(string $subDir,string $fileName):string
{
    // 通过下载接口输出文件，避免对外暴露服务器的存储路径。
    return absolute_app_url('api/download_file.php?id='.rawurlencode($subDir));
}

$pdo=db();
try {
    $pdo->beginTransaction();
    $stmt=$pdo->prepare('SELECT id FROM contract_forms WHERE status=:status ORDER BY id ASC LIMIT 1 FOR UPDATE');
    $stmt->execute([':status'=>3]); $recognizing=$stmt->fetch();
    if($recognizing){$pdo->commit();json_response(['success'=>true,'has_task'=>false,'message'=>'已有识别中任务，暂不派发新任务','recognizing_task_id'=>(int)$recognizing['id']]);}
    $stmt=$pdo->prepare("SELECT id, attachment_contract_quote_epo, created_at, created_by_mail FROM contract_forms WHERE status=:status AND attachment_contract_quote_epo<>'' AND attachment_contract_quote_epo IS NOT NULL ORDER BY id ASC LIMIT 1 FOR UPDATE");
    $stmt->execute([':status'=>2]);$task=$stmt->fetch();
    if(!$task){$pdo->commit();json_response(['success'=>true,'has_task'=>false,'message'=>'暂无待识别任务']);}
    $update=$pdo->prepare('UPDATE contract_forms SET status=3, recognition_started_at=COALESCE(recognition_started_at,NOW()), updated_at=NOW() WHERE id=:id AND status=2');
    $update->execute([':id'=>(int)$task['id']]);
    if($update->rowCount()!==1) throw new RuntimeException('任务状态发生变化，请重试。');
    $log=$pdo->prepare("INSERT INTO logs (operator,task_id,operation_time,operation_content,task_status) VALUES ('API:get_task',:task_id,NOW(),'开始识别',3)");
    $log->execute([':task_id'=>(int)$task['id']]);
    $pdo->commit();$file=(string)$task['attachment_contract_quote_epo'];
    json_response(['success'=>true,'has_task'=>true,'task'=>['id'=>(int)$task['id'],'status'=>3,'status_text'=>status_label(3),'created_at'=>$task['created_at'],'created_by_mail'=>(string)($task['created_by_mail']??''),'contract_quote_epo_file'=>$file,'contract_quote_epo_url'=>file_download_url((string)$task['id'],$file)]]);
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    json_response(['success'=>false,'message'=>'获取待识别任务失败','error'=>$e->getMessage()],500);
}
