<?php
require __DIR__ . '/../includes/bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => '仅支持 POST。'], 405);
}
$payload = request_payload();
write_app_log('api', '【P系统返回原始数据】', ['data' => $payload]);
$taskId = parse_task_id($payload['intellisight_id'] ?? $payload['id'] ?? $payload['task_id'] ?? 0);
if ($taskId < 1) {
    json_response(['success' => false, 'message' => '缺少有效的 intellisight_id。'], 422);
}
$upcValues = $payload['upc_code'] ?? [];
if (!is_array($upcValues)) $upcValues = split_result_values((string)$upcValues);
$upcValues = array_map(static function ($value): string { return trim((string)$value); }, $upcValues);

$pdo = db();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id,status,vendor_part_no,description FROM contract_forms WHERE id=:id LIMIT 1 FOR UPDATE');
    $stmt->execute([':id' => $taskId]);
    $task = $stmt->fetch();
    if (!$task) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => '找不到对应任务。'], 404);
    }
    if ((int)$task['status'] !== 4) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => '仅匹配中的任务可接收 P 系统返回。'], 409);
    }
    $rowCount = max(count(split_result_values($task['vendor_part_no'])), count(split_result_values($task['description'])));
    if (count($upcValues) !== $rowCount || in_array('', $upcValues, true)) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => '每一行都必须提供一个 upc_code。'], 422);
    }
    $update = $pdo->prepare('UPDATE contract_forms SET upc_code=:upc,status=5,matching_finished_at=NOW(),updated_at=NOW() WHERE id=:id');
    $update->execute([':upc' => implode(';', $upcValues), ':id' => $taskId]);
    $log = $pdo->prepare("INSERT INTO logs (operator,task_id,operation_time,operation_content,task_status) VALUES ('API:p_sys_back',:task_id,NOW(),'P系统匹配结果返回',5)");
    $log->execute([':task_id' => $taskId]);
    $pdo->commit();
    json_response(['success' => true, 'message' => '匹配结果已保存', 'task_id' => format_task_no($taskId), 'status' => 5, 'status_text' => status_label(5)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    write_app_log('api', '保存P系统返回失败', ['task_id' => $taskId, 'error' => $e->getMessage()]);
    json_response(['success' => false, 'message' => 'P 系统返回保存失败'], 500);
}
