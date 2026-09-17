<?php
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/p_system.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(['success' => false, 'message' => '仅支持 POST。'], 405);
}
$payload = request_payload();
write_app_log('api', '【请求P系统接口原始数据】', ['data' => $payload]);
$taskId = parse_task_id($payload['task_id'] ?? 0);
if ($taskId < 1) {
    json_response(['success' => false, 'message' => '缺少有效的 task_id。'], 422);
}

try {
    $result = submit_task_to_p_system($taskId);
    json_response([
        'success' => true,
        'message' => '已请求模拟 P 系统',
        'task_id' => $taskId,
        'status' => 4,
        'status_text' => status_label(4),
        'data' => $result['payload'],
        'mock_url' => $result['mock_url'] ?? null,
    ]);
} catch (RuntimeException $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 409);
} catch (Throwable $e) {
    write_app_log('api', '请求P系统失败', ['task_id' => $taskId, 'error' => $e->getMessage()]);
    json_response(['success' => false, 'message' => '请求 P 系统失败'], 500);
}
