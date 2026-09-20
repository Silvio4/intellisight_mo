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

function post_to_p_system(string $endpoint, array $payload, int $timeout): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new RuntimeException('P 系统请求数据无法编码。');
    }
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
        $error = json_decode((string)$response, true);
        $reason = is_array($error) && !empty($error['message'])
            ? (string)$error['message']
            : ($statusLine ?: '无法连接');
        throw new RuntimeException('P 系统请求失败：' . $reason);
    }

    $result = json_decode($response, true);
    if (!is_array($result) || ($result['success'] ?? false) !== true) {
        $reason = is_array($result) && !empty($result['message']) ? (string)$result['message'] : '响应格式不正确';
        throw new RuntimeException('P 系统未确认接收任务：' . $reason);
    }
    return $result;
}

function submit_task_to_p_system(int $taskId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM contract_forms WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $taskId]);
    $task = $stmt->fetch();
    if (!$task) throw new RuntimeException('找不到对应任务。');
    if ((int)$task['status'] !== 3) throw new RuntimeException('仅识别中的任务可以请求 P 系统。');

    $payload = p_system_payload($task);
    write_app_log('api', '【请求P系统原始数据】', $payload);
    $endpoint = trim((string)config_value('p_system.endpoint', ''));
    if ($endpoint === '') {
        throw new RuntimeException('未配置 P 系统任务接收地址。');
    }
    $response = post_to_p_system($endpoint, $payload, max(1, (int)config_value('p_system.timeout_seconds', 10)));
    write_app_log('api', '【P系统接收响应】', $response);

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
        'response' => $response,
    ];
}
