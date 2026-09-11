<?php

/**
 * Build the P-system request in one place so the mock can later be replaced by
 * a real HTTP integration without changing the recognition callback.
 */
function p_system_payload(array $task): array
{
    return [
        'data' => [
            'intellisight_id' => format_task_no($task['id']),
            'po_no' => (string)$task['po_no'],
            'delivery_address' => (string)$task['delivery_address'],
            'no' => split_result_values($task['no']),
            'vendor_part_no' => split_result_values($task['vendor_part_no']),
            'description' => split_result_values($task['description']),
            'qty' => split_result_values($task['qty']),
            'unit_cost' => split_result_values($task['unit_cost']),
            'discount' => $task['discount'],
        ],
    ];
}

function submit_task_to_p_system(int $taskId): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM contract_forms WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $taskId]);
    $task = $stmt->fetch();
    if (!$task) {
        throw new RuntimeException('找不到对应任务。');
    }
    if ((int)$task['status'] < 3) {
        throw new RuntimeException('任务尚未完成识别，不能请求 P 系统。');
    }

    $payload = p_system_payload($task);
    write_app_log('api', '【请求P系统原始数据】', $payload);

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE contract_forms SET status=4, matching_started_at=COALESCE(matching_started_at,NOW()), updated_at=NOW() WHERE id=:id');
        $update->execute([':id' => $taskId]);
        $log = $pdo->prepare("INSERT INTO logs (operator,task_id,operation_time,operation_content,task_status) VALUES ('API:submit_p_sys',:task_id,NOW(),'请求P系统',4)");
        $log->execute([':task_id' => $taskId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return [
        'payload' => $payload,
        'mock_url' => absolute_app_url('p_system_mock.php?id=' . $taskId),
    ];
}
