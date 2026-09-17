<?php

/** 构造与智眸识别返回完全相同的 P 系统请求格式。 */
function p_system_payload(array $task): array
{
    $data = ['task_id' => (int)$task['id']];
    foreach ([
        'po_no', 'customer_name', 'customer_delivery_address', 'end_user_name',
        'end_user_contact', 'end_user_email', 'vendor_part_no', 'description',
        'qty', 'price_currency', 'unit_price',
    ] as $field) {
        $data[$field] = (string)($task[$field] ?? '');
    }
    return $data;
}

function post_to_p_system(string $endpoint, array $payload, int $timeout): void
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json; charset=utf-8\r\nAccept: application/json\r\n",
        'content' => $body,
        'timeout' => $timeout,
        'ignore_errors' => true,
    ]]);
    $response = @file_get_contents($endpoint, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    if ($response === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
        throw new RuntimeException('P 系统请求失败：' . ($statusLine ?: '无法连接'));
    }
}

function submit_task_to_p_system(int $taskId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM contract_forms WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $taskId]);
    $task = $stmt->fetch();
    if (!$task) throw new RuntimeException('找不到对应任务。');
    if ((int)$task['status'] < 3) throw new RuntimeException('任务尚未完成识别，不能请求 P 系统。');

    $payload = p_system_payload($task);
    write_app_log('api', '【请求P系统原始数据】', $payload);
    $endpoint = trim((string)config_value('p_system.endpoint', ''));
    if ($endpoint !== '') {
        post_to_p_system($endpoint, $payload, max(1, (int)config_value('p_system.timeout_seconds', 10)));
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE contract_forms SET status=4,matching_started_at=COALESCE(matching_started_at,NOW()),updated_at=NOW() WHERE id=:id');
        $update->execute([':id' => $taskId]);
        $log = $pdo->prepare("INSERT INTO logs (operator,task_id,operation_time,operation_content,task_status) VALUES ('API:submit_p_sys',:task_id,NOW(),'请求P系统成功',4)");
        $log->execute([':task_id' => $taskId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return [
        'payload' => $payload,
        'mock_url' => $endpoint === '' ? absolute_app_url('p_system_mock.php?id=' . $taskId) : null,
    ];
}
