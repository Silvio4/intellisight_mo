<?php
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/costing_sheet.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => '仅支持 POST。'], 405);
}

$payload = request_payload();
$taskId = parse_task_id($payload['task_id'] ?? 0);
if ($taskId < 1) {
    json_response(['success' => false, 'message' => '缺少有效的 task_id。'], 422);
}

try {
    $result = generate_costing_sheet($taskId);
    json_response([
        'success' => true,
        'message' => $result['generated'] ? 'Costing Sheet 已生成。' : 'Costing Sheet 已存在。',
        'task_id' => $taskId,
        'status' => 6,
        'status_text' => status_label(6),
        'filename' => $result['filename'],
    ]);
} catch (Throwable $exception) {
    write_app_log('api', '生成 Costing Sheet 失败', ['task_id' => $taskId, 'error' => $exception->getMessage()]);
    json_response(['success' => false, 'message' => 'Costing Sheet 生成失败：' . $exception->getMessage(), 'task_id' => $taskId], 500);
}
