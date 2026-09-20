<?php
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/p_system.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => '仅支持 POST。'], 405);
}

$payload = request_payload();
write_app_log('api', '【识别返回原始数据】', ['data' => $payload]);

function normalize_recognition_field($value): string
{
    if (is_array($value)) {
        $value = implode(';', array_map(static function ($item): string {
            return trim((string)$item);
        }, $value));
    }
    return is_scalar($value) || $value === null ? trim((string)$value) : '';
}

$taskId = parse_task_id($payload['task_id'] ?? 0);
if ($taskId < 1) {
    json_response(['success' => false, 'message' => '缺少有效的 task_id。'], 422);
}

$fields = [
    'po_no', 'customer_name', 'customer_delivery_address', 'end_user_name',
    'end_user_contact', 'end_user_email', 'vendor_part_no', 'description',
    'qty', 'price_currency', 'unit_price',
];
$values = [];
foreach ($fields as $field) {
    $values[$field] = normalize_recognition_field($payload[$field] ?? '');
}

$multiFields = ['vendor_part_no', 'description', 'qty', 'price_currency', 'unit_price'];
$counts = [];
foreach ($multiFields as $field) {
    $counts[$field] = count(split_result_values($values[$field]));
}
if (count(array_unique(array_values($counts))) > 1) {
    json_response([
        'success' => false,
        'message' => 'vendor_part_no、description、qty、price_currency、unit_price 的项目数量必须一致。',
    ], 422);
}

$pdo = db();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id,status FROM contract_forms WHERE id=:id LIMIT 1 FOR UPDATE');
    $stmt->execute([':id' => $taskId]);
    $task = $stmt->fetch();
    if (!$task) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => '找不到对应任务。'], 404);
    }
    if ((int)$task['status'] !== 3) {
        $pdo->rollBack();
        json_response(['success' => false, 'message' => '仅识别中的任务可接收识别结果。'], 409);
    }

    $assignments = [];
    $params = [':raw_result' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':id' => $taskId];
    foreach ($fields as $field) {
        $assignments[] = $field . '=:' . $field;
        $params[':' . $field] = $values[$field];
    }
    $sql = 'UPDATE contract_forms SET ' . implode(',', $assignments)
        . ',raw_result=:raw_result,recognition_finished_at=COALESCE(recognition_finished_at,NOW()),updated_at=NOW() WHERE id=:id';
    $pdo->prepare($sql)->execute($params);
    $log = $pdo->prepare("INSERT INTO logs (operator,task_id,operation_time,operation_content,task_status) VALUES ('API:returndata',:task_id,NOW(),'识别结果回传',3)");
    $log->execute([':task_id' => $taskId]);
    $pdo->commit();

    // P 系统请求成功后，submit_task_to_p_system 才会把任务更新为“匹配中”。
    $pResult = submit_task_to_p_system($taskId);
    json_response([
        'success' => true,
        'message' => '识别结果已保存并成功请求 P 系统。',
        'task_id' => $taskId,
        'status' => 4,
        'status_text' => status_label(4),
        'p_system_response' => $pResult['response'],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    write_app_log('api', '识别结果处理失败', ['task_id' => $taskId, 'error' => $e->getMessage()]);
    json_response(['success' => false, 'message' => '识别结果已保存，但请求 P 系统失败。', 'task_id' => $taskId], 502);
}
