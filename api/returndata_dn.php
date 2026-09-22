<?php
require __DIR__ . '/../includes/bootstrap.php';
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_response(['success'=>false,'message'=>'仅支持 POST。'], 405);

$dnTaskId = (int)($_POST['dn_task_id'] ?? $_POST['task_id'] ?? 0);
$upload = $_FILES['dn_file'] ?? $_FILES['file'] ?? null;
if ($dnTaskId < 1) json_response(['success'=>false,'message'=>'缺少有效的 dn_task_id。'], 422);
if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_response(['success'=>false,'message'=>'请以 dn_file 字段上传生成后的 PDF。'], 422);
if ((int)$upload['size'] < 1 || (int)$upload['size'] > 30 * 1024 * 1024) json_response(['success'=>false,'message'=>'返回文件大小必须在 30MB 以内。'], 422);
$tmp = (string)$upload['tmp_name'];
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
if (strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION)) !== 'pdf' || $mime !== 'application/pdf') json_response(['success'=>false,'message'=>'返回文件必须是有效的 PDF。'], 422);

$pdo = db();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id,order_task_id,upload_file_name,status FROM dn_task_pool WHERE id=:id LIMIT 1 FOR UPDATE');
    $stmt->execute([':id'=>$dnTaskId]);
    $task = $stmt->fetch();
    if (!$task) { $pdo->rollBack(); json_response(['success'=>false,'message'=>'找不到对应 DN 任务。'], 404); }
    if ((int)$task['status'] !== 2) { $pdo->rollBack(); json_response(['success'=>false,'message'=>'仅识别中的 DN 任务可接收返回文件。'], 409); }
    $fileName = pathinfo((string)$task['upload_file_name'], PATHINFO_FILENAME).'_done.pdf';
    $directory = dirname(__DIR__).'/files/dn_done';
    if (!is_dir($directory) && !mkdir($directory, 0775, true)) throw new RuntimeException('无法创建 DN 完成文件目录。');
    $destination = $directory.'/'.$fileName;
    if (!move_uploaded_file($tmp, $destination)) throw new RuntimeException('返回文件保存失败。');
    try {
        $update = $pdo->prepare('UPDATE dn_task_pool SET done_file_name=:file,status=3,recognition_finished_at=NOW(),updated_at=NOW() WHERE id=:id');
        $update->execute([':file'=>$fileName, ':id'=>$dnTaskId]);
        $pdo->commit();
    } catch (Throwable $e) { @unlink($destination); throw $e; }
    json_response(['success'=>true,'message'=>'DN 返回文件已保存。','dn_task_id'=>$dnTaskId,'order_task_id'=>(int)$task['order_task_id'],'status'=>3,'status_text'=>'已完成','done_file_name'=>$fileName]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    write_app_log('api', 'DN返回处理失败', ['dn_task_id'=>$dnTaskId,'error'=>$e->getMessage()]);
    json_response(['success'=>false,'message'=>'DN 返回文件处理失败。'], 500);
}
