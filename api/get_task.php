<?php
require __DIR__ . '/../includes/bootstrap.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
app_request_log('dispatch.request_received', ['include_base64' => ($_GET['include_base64'] ?? $_POST['include_base64'] ?? '0') === '1']);
if (!in_array($method, ['GET', 'POST'], true)) {
    json_response(['success' => false, 'code' => 'method_not_allowed', 'message' => '仅支持 GET 或 POST。'], 405);
}

function api_task_payload(PDO $pdo, array $task, bool $includeBase64): array
{
    app_request_log('dispatch.files_loading', ['task_no' => $task['task_no'], 'include_base64' => $includeBase64]);
    $fileStmt = $pdo->prepare('SELECT id, original_name, pdf_name, file_size, sha256 FROM contract_task_files WHERE task_id = :task_id AND conversion_status = \'success\' ORDER BY id ASC');
    $fileStmt->execute([':task_id' => $task['id']]);
    $files = [];
    foreach ($fileStmt->fetchAll() as $file) {
        $path = dirname(__DIR__) . '/files/' . $task['task_no'] . '/' . basename($file['pdf_name']);
        if (!is_file($path)) {
            app_request_log('dispatch.file_missing', ['task_no' => $task['task_no'], 'file_id' => (int)$file['id']]);
            continue;
        }
        $downloadUrl = absolute_app_url('api/download_file.php?file_id=' . (int)$file['id'] . '&token=' . rawurlencode((string)$task['claim_token']));
        $item = [
            'file_id' => (int)$file['id'],
            'original_name' => $file['original_name'],
            'pdf_name' => $file['pdf_name'],
            'pdf_size' => filesize($path),
            'sha256' => hash_file('sha256', $path),
            'download_url' => $downloadUrl,
        ];
        if ($includeBase64) {
            $item['content_type'] = 'application/pdf';
            $item['content_base64'] = base64_encode((string)file_get_contents($path));
        }
        $files[] = $item;
    }
    app_request_log('dispatch.payload_ready', ['task_no' => $task['task_no'], 'pdf_count' => count($files)]);
    return [
        'task_no' => $task['task_no'],
        'status' => $task['status'],
        'status_text' => status_label($task['status']),
        'sales_person' => $task['sales_person'],
        'created_at' => $task['created_at'],
        'claimed_at' => $task['claimed_at'],
        'claim_token' => $task['claim_token'],
        'pdf_count' => count($files),
        'files' => $files,
    ];
}

$includeBase64 = ($_GET['include_base64'] ?? $_POST['include_base64'] ?? '0') === '1';
if ($includeBase64 && !config_value('api.allow_base64', true)) {
    json_response(['success' => false, 'code' => 'base64_disabled', 'message' => '服务器未启用 Base64 文件返回。'], 400);
}

$pdo = db();
$lockName = 'intellisight_mo_task_dispatch';
$lockAcquired = false;
try {
    app_request_log('dispatch.lock_waiting');
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 8)');
    $lockStmt->execute([':lock_name' => $lockName]);
    $lockAcquired = (int)$lockStmt->fetchColumn() === 1;
    if (!$lockAcquired) {
        app_request_log('dispatch.lock_timeout');
        json_response(['success' => false, 'code' => 'dispatch_busy', 'message' => '任务派发锁繁忙，请稍后重试。'], 503);
    }
    app_request_log('dispatch.lock_acquired');

    $pdo->beginTransaction();
    $currentStmt = $pdo->query("SELECT * FROM contract_tasks WHERE status = 'recognizing' ORDER BY claimed_at ASC, id ASC LIMIT 1 FOR UPDATE");
    $task = $currentStmt->fetch();

    if ($task) {
        if (empty($task['claim_token'])) {
            $task['claim_token'] = bin2hex(random_bytes(24));
            $tokenStmt = $pdo->prepare('UPDATE contract_tasks SET claim_token = :token, updated_at = NOW() WHERE id = :id');
            $tokenStmt->execute([':token' => $task['claim_token'], ':id' => $task['id']]);
        }
        $pdo->commit();
        $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $releaseStmt->execute([':lock_name' => $lockName]);
        $lockAcquired = false;
        app_request_log('dispatch.existing_task_returned', ['task_no' => $task['task_no']]);
        json_response([
            'success' => true,
            'code' => 'recognition_in_progress',
            'message' => '当前已有识别中的任务，本次不派发新任务。',
            'dispatched' => false,
            'task' => api_task_payload($pdo, $task, $includeBase64),
        ]);
    }

    $pendingStmt = $pdo->query("SELECT * FROM contract_tasks WHERE status = 'pending' ORDER BY created_at ASC, id ASC LIMIT 1 FOR UPDATE");
    $task = $pendingStmt->fetch();
    if (!$task) {
        $pdo->commit();
        $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $releaseStmt->execute([':lock_name' => $lockName]);
        $lockAcquired = false;
        app_request_log('dispatch.queue_empty');
        json_response([
            'success' => true,
            'code' => 'no_pending_task',
            'message' => '目前没有待识别任务，本次不派发任务。',
            'dispatched' => false,
            'task' => null,
        ]);
    }

    $task['claim_token'] = bin2hex(random_bytes(24));
    $updateStmt = $pdo->prepare("UPDATE contract_tasks SET status = 'recognizing', claimed_at = NOW(), claim_token = :token, updated_at = NOW() WHERE id = :id AND status = 'pending'");
    $updateStmt->execute([':token' => $task['claim_token'], ':id' => $task['id']]);
    if ($updateStmt->rowCount() !== 1) {
        throw new RuntimeException('任务状态发生变化，请重试。');
    }
    $task['status'] = 'recognizing';
    $task['claimed_at'] = date('Y-m-d H:i:s');
    $pdo->commit();
    app_request_log('dispatch.task_claimed', ['task_no' => $task['task_no']]);

    $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
    $releaseStmt->execute([':lock_name' => $lockName]);
    $lockAcquired = false;
    json_response([
        'success' => true,
        'code' => 'task_dispatched',
        'message' => '已派发一条待识别任务，并更新为识别中。',
        'dispatched' => true,
        'task' => api_task_payload($pdo, $task, $includeBase64),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($lockAcquired) {
        try {
            $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $releaseStmt->execute([':lock_name' => $lockName]);
        } catch (Throwable $ignored) {
        }
    }
    write_app_log('api_get_task', '任务派发异常', ['message' => $e->getMessage()]);
    app_request_log('dispatch.failed', ['error' => $e->getMessage()]);
    json_response([
        'success' => false,
        'code' => 'dispatch_error',
        'message' => config_value('app.debug', false) ? $e->getMessage() : '任务派发失败，请查看服务器日志。',
    ], 500);
}
