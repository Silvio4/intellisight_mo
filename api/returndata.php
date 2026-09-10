<?php
require __DIR__ . '/../includes/bootstrap.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'code' => 'method_not_allowed', 'message' => '仅支持 POST。'], 405);
}

$payload = request_payload();
app_request_log('result.request_received', ['payload_keys' => array_keys($payload)]);

function normalize_result_field($value): string
{
    if (is_array($value)) {
        $value = implode(';', array_map(static function ($item) {
            return trim((string)$item);
        }, $value));
    }
    if (!is_scalar($value) && $value !== null) {
        return '';
    }
    return trim((string)$value);
}

$taskNo = normalize_result_field($payload['task_no'] ?? '');
$poNo = normalize_result_field($payload['po_no'] ?? '');
$deliveryAddress = normalize_result_field($payload['delivery_address'] ?? '');
$no = normalize_result_field($payload['no'] ?? '');
$vendorPartNo = normalize_result_field($payload['vendor_part_no'] ?? '');
$description = normalize_result_field($payload['description'] ?? '');
$qty = normalize_result_field($payload['qty'] ?? '');
$unitCost = normalize_result_field($payload['unit_cost'] ?? ($payload['unit_price'] ?? ''));
$discountRaw = $payload['discount'] ?? null;
$discount = ($discountRaw === null || $discountRaw === '') ? null : normalize_result_field($discountRaw);

if ($taskNo === '') {
    app_request_log('result.validation_failed', ['reason' => 'missing_task_no']);
    json_response(['success' => false, 'code' => 'missing_task_no', 'message' => '缺少 task_no。'], 422);
}
if ($discount !== null && !is_numeric($discount)) {
    app_request_log('result.validation_failed', ['task_no' => $taskNo, 'reason' => 'invalid_discount']);
    json_response(['success' => false, 'code' => 'invalid_discount', 'message' => 'discount 必须是数字，也可以是负数。'], 422);
}

$lineFields = [
    'no' => $no,
    'vendor_part_no' => $vendorPartNo,
    'description' => $description,
    'qty' => $qty,
    'unit_cost' => $unitCost,
];
$counts = [];
foreach ($lineFields as $key => $value) {
    $counts[$key] = $value === '' ? 0 : count(explode(';', $value));
}
$nonZeroCounts = array_values(array_filter($counts, static function ($count) { return $count > 0; }));
$warnings = [];
if ($nonZeroCounts && count(array_unique($nonZeroCounts)) > 1) {
    $warnings[] = '明细字段的分号分隔数量不一致，请在任务详情中核对行对应关系。';
}

$pdo = db();
try {
    app_request_log('result.transaction_started', ['task_no' => $taskNo]);
    $pdo->beginTransaction();
    $taskStmt = $pdo->prepare('SELECT id, task_no, status FROM contract_tasks WHERE task_no = :task_no LIMIT 1 FOR UPDATE');
    $taskStmt->execute([':task_no' => $taskNo]);
    $task = $taskStmt->fetch();
    if (!$task) {
        $pdo->rollBack();
        app_request_log('result.task_not_found', ['task_no' => $taskNo]);
        json_response(['success' => false, 'code' => 'task_not_found', 'message' => '找不到对应任务号。'], 404);
    }
    if (!in_array($task['status'], ['recognizing', 'completed'], true)) {
        $pdo->rollBack();
        app_request_log('result.invalid_status', ['task_no' => $taskNo, 'status' => $task['status']]);
        json_response([
            'success' => false,
            'code' => 'invalid_task_status',
            'message' => '当前任务状态为“' . status_label($task['status']) . '”，不能接收识别结果。',
        ], 409);
    }

    $resultStmt = $pdo->prepare(
        'INSERT INTO contract_form
            (task_id, po_no, delivery_address, `no`, vendor_part_no, description, qty, unit_cost, discount, created_at, updated_at)
         VALUES
            (:task_id, :po_no, :delivery_address, :no, :vendor_part_no, :description, :qty, :unit_cost, :discount, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            po_no = VALUES(po_no), delivery_address = VALUES(delivery_address), `no` = VALUES(`no`),
            vendor_part_no = VALUES(vendor_part_no), description = VALUES(description), qty = VALUES(qty),
            unit_cost = VALUES(unit_cost), discount = VALUES(discount), updated_at = NOW()'
    );
    $resultStmt->execute([
        ':task_id' => $task['id'],
        ':po_no' => $poNo,
        ':delivery_address' => $deliveryAddress,
        ':no' => $no,
        ':vendor_part_no' => $vendorPartNo,
        ':description' => $description,
        ':qty' => $qty,
        ':unit_cost' => $unitCost,
        ':discount' => $discount,
    ]);
    app_request_log('result.data_saved', ['task_no' => $taskNo, 'line_counts' => $counts]);

    $rawResult = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $updateStmt = $pdo->prepare(
        "UPDATE contract_tasks
            SET status = 'completed', raw_result = :raw_result, completed_at = COALESCE(completed_at, NOW()), error_message = NULL, updated_at = NOW()
          WHERE id = :id"
    );
    $updateStmt->execute([':raw_result' => $rawResult, ':id' => $task['id']]);
    $pdo->commit();
    app_request_log('result.completed', ['task_no' => $taskNo, 'warnings' => $warnings]);

    json_response([
        'success' => true,
        'code' => 'result_saved',
        'message' => '识别结果已保存，任务状态已更新为已完成。',
        'task_no' => $taskNo,
        'status' => 'completed',
        'status_text' => status_label('completed'),
        'line_counts' => $counts,
        'warnings' => $warnings,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    write_app_log('api_returndata', '识别结果保存异常', ['task_no' => $taskNo, 'message' => $e->getMessage()]);
    app_request_log('result.failed', ['task_no' => $taskNo, 'error' => $e->getMessage()]);
    json_response([
        'success' => false,
        'code' => 'save_error',
        'message' => config_value('app.debug', false) ? $e->getMessage() : '识别结果保存失败，请查看服务器日志。',
    ], 500);
}
