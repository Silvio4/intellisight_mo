<?php
require __DIR__ . '/includes/auth.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('仅支持 POST。');
}
verify_csrf();
$taskId = (int)($_POST['task_id'] ?? 0);
$returnPath = 'contract_form_view.php?id=' . $taskId;
$upload = $_FILES['dn_file'] ?? null;

try {
    if ($taskId < 1 || !is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('请选择需要上传的 PDF 文件。');
    }
    if ((int)$upload['size'] < 1 || (int)$upload['size'] > 30 * 1024 * 1024) {
        throw new RuntimeException('DN 文件大小必须在 30MB 以内。');
    }
    $tmp = (string)$upload['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (strtolower(pathinfo((string)$upload['name'], PATHINFO_EXTENSION)) !== 'pdf' || $mime !== 'application/pdf') {
        throw new RuntimeException('DN 仅支持有效的 PDF 文件。');
    }

    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id,created_by,recognition_finished_at FROM contract_forms WHERE id=:id LIMIT 1 FOR UPDATE');
    $stmt->execute([':id' => $taskId]);
    $task = $stmt->fetch();
    if (!$task) throw new RuntimeException('订单任务不存在。');
    if (current_user_role() === 'submitter' && (int)$task['created_by'] !== (int)$_SESSION['user_id']) {
        throw new RuntimeException('无权为该任务上传 DN。');
    }
    if ($task['recognition_finished_at'] === null) {
        throw new RuntimeException('订单识别完成后才可上传 DN。');
    }
    $seqStmt = $pdo->prepare('SELECT COALESCE(MAX(sequence_no),0)+1 FROM dn_task_pool WHERE order_task_id=:id');
    $seqStmt->execute([':id' => $taskId]);
    $sequence = (int)$seqStmt->fetchColumn();
    $fileName = 'dn_' . format_task_no($taskId) . '_' . date('Ymd') . '_' . $sequence . '.pdf';
    $directory = __DIR__ . '/files/dn';
    if (!is_dir($directory) && !mkdir($directory, 0775, true)) throw new RuntimeException('无法创建 DN 存储目录。');
    $destination = $directory . '/' . $fileName;
    if (!move_uploaded_file($tmp, $destination)) throw new RuntimeException('DN 文件保存失败。');
    try {
        $insert = $pdo->prepare('INSERT INTO dn_task_pool (order_task_id,sequence_no,upload_file_name,original_file_name,status,uploaded_by,uploaded_at) VALUES (:task_id,:sequence,:stored,:original,1,:user_id,NOW())');
        $insert->execute([':task_id'=>$taskId, ':sequence'=>$sequence, ':stored'=>$fileName, ':original'=>basename((string)$upload['name']), ':user_id'=>(int)$_SESSION['user_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        @unlink($destination);
        throw $e;
    }
    write_app_log('app', 'DN上传成功', ['task_id'=>$taskId, 'dn_task_id'=>(int)$pdo->lastInsertId(), 'file'=>$fileName]);
    flash('success', 'DN 已上传，正在等待识别。');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    write_app_log('app', 'DN上传失败', ['task_id'=>$taskId, 'error'=>$e->getMessage()]);
    flash('error', $e->getMessage());
}
redirect($returnPath);
