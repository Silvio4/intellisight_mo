<?php
require __DIR__ . '/../includes/bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => '仅支持 POST。'], 405);
}
$payload = request_payload();
write_app_log('api', '【P系统返回原始数据】', ['data' => $payload]);
$taskId = parse_task_id($payload['task_id'] ?? 0);
if ($taskId < 1) {
    json_response(['success' => false, 'message' => '缺少有效的 task_id。'], 422);
}
$recognitionFields = [
    'po_no', 'customer_name', 'customer_delivery_address', 'end_user_name',
    'end_user_contact', 'end_user_email', 'vendor_part_no', 'description',
    'qty', 'price_currency', 'unit_price',
];
$missingFields = array_values(array_filter($recognitionFields, static function (string $field) use ($payload): bool {
    return !array_key_exists($field, $payload);
}));
if ($missingFields) {
    json_response(['success' => false, 'message' => 'P 系统返回必须带回原识别字段。', 'missing_fields' => $missingFields], 422);
}

$pidValues = $payload['pid'] ?? '';
if (is_array($pidValues)) {
    $pidValues = implode(';', array_map('strval', $pidValues));
}
$pidValues = trim((string)$pidValues);
$pSystemLink = trim((string)($payload['p_sys_link'] ?? ''));

$pdo = db();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id,status,vendor_part_no,description,qty,price_currency,unit_price FROM contract_forms WHERE id=:id LIMIT 1 FOR UPDATE');
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
    $rowCount = count(split_result_values($task['vendor_part_no']));
    $pids = split_result_values($pidValues);
    if ($rowCount < 1 || count($pids) !== $rowCount || in_array('', $pids, true)) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'pid 数量必须与识别明细行数量一致，且每一行均不能为空。'], 422);
    }
    $linkParts = parse_url($pSystemLink);
    if ($pSystemLink === '' || !filter_var($pSystemLink, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string)($linkParts['scheme'] ?? '')), ['http', 'https'], true)) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => 'p_sys_link 必须是有效的 HTTP 或 HTTPS 链接。'], 422);
    }

    $update = $pdo->prepare('UPDATE contract_forms SET pid=:pid,p_sys_link=:p_sys_link,status=5,matching_finished_at=NOW(),updated_at=NOW() WHERE id=:id');
    $update->execute([':pid' => implode(';', $pids), ':p_sys_link' => $pSystemLink, ':id' => $taskId]);
    $log = $pdo->prepare("INSERT INTO logs (operator,task_id,operation_time,operation_content,task_status) VALUES ('API:p_sys_back',:task_id,NOW(),'P系统匹配结果返回',5)");
    $log->execute([':task_id' => $taskId]);
    $pdo->commit();
    json_response(['success' => true, 'message' => 'P 系统匹配结果已保存。', 'task_id' => $taskId, 'status' => 5, 'status_text' => status_label(5)]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    write_app_log('api', '保存P系统返回失败', ['task_id' => $taskId, 'error' => $e->getMessage()]);
    json_response(['success' => false, 'message' => 'P 系统返回保存失败。'], 500);
}
