<?php

function eportal_response_error_message(string $body, int $httpStatus): string
{
    $response = json_decode($body, true);
    if (is_array($response)) {
        $message = trim((string)($response['message'] ?? $response['error'] ?? ''));
        return $message !== '' ? $message : '未确认建单成功';
    }

    // ThinkPHP 错误页会把真实的数据库错误放在 h1 中。提取该内容，
    // 避免把可操作的服务端报错统一隐藏成“响应不是有效 JSON”。
    if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $body, $matches) === 1) {
        $message = trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($message !== '') {
            return $message;
        }
    }

    $status = $httpStatus > 0 ? 'HTTP ' . $httpStatus . '，' : '';
    return $status . '响应不是有效 JSON';
}

function eportal_response_is_success(array $response, int $httpStatus): bool
{
    if ($httpStatus < 200 || $httpStatus >= 300) {
        return false;
    }

    if (($response['success'] ?? false) === true) {
        return true;
    }

    return ($response['state'] ?? null) === 1 || ($response['state'] ?? null) === '1';
}

function eportal_response_ticket_no(array $response): string
{
    if (array_key_exists('ticket_no', $response)) {
        return trim((string)$response['ticket_no']);
    }
    if (array_key_exists('id', $response)) {
        return trim((string)$response['id']);
    }

    // MO ePortal 以 {"state":1,"msg":"19"} 返回成功结果，msg 为 ePortal 单号。
    if ((($response['state'] ?? null) === 1 || ($response['state'] ?? null) === '1')
        && preg_match('/^\d+$/', trim((string)($response['msg'] ?? ''))) === 1) {
        return trim((string)$response['msg']);
    }

    return '';
}

function eportal_payload(array $task): array
{
    $userFields = ['applicant_id', 'applicant', 'applicant_mail', 'buyer_mail', 'buyer',
        'buyer_boss', 'buyer_boss_mail', 'ratifier', 'ratifier_mail'];
    foreach ($userFields as $field) {
        if (trim((string)($task[$field] ?? '')) === '') {
            throw new RuntimeException('任务创建人缺少 ePortal 必填资料：' . $field . '。');
        }
    }

    $columns = [];
    foreach (['pid','description','vendor_part_no','qty','price_currency','unit_price'] as $field) {
        $columns[$field] = split_result_values((string)($task[$field] ?? ''));
    }
    $count = count($columns['pid']);
    if ($count < 1) throw new RuntimeException('没有可提交的产品明细。');
    foreach ($columns as $field => $values) {
        if (count($values) !== $count) throw new RuntimeException($field . ' 的明细数量不一致。');
    }

    $products = [];
    $totalCost = 0.0;
    $totalPrice = 0.0;
    $gstRate = (float)config_value('eportal.gst_rate', 7);
    foreach (range(0, $count - 1) as $index) {
        $qty = (float)$columns['qty'][$index];
        $unitPrice = (float)$columns['unit_price'][$index];
        // 当前识别结果没有独立成本字段；在表单扩展该字段前，以识别单价作为成本基准。
        $unitCost = $unitPrice;
        $lineCost = $qty * $unitCost;
        $linePrice = $qty * $unitPrice;
        $gst = $linePrice * $gstRate / 100;
        $totalCost += $lineCost;
        $totalPrice += $linePrice;
        $products[] = [
            'product_id'=>$columns['pid'][$index], 'description'=>$columns['description'][$index],
            'PN'=>$columns['vendor_part_no'][$index], 'node_id'=>(string)config_value('eportal.node_id', 'JOSM'),
            'biz_category'=>(string)config_value('eportal.biz_category', 'Product'),
            'qty'=>$columns['qty'][$index], 'currency'=>$columns['price_currency'][$index],
            'unit_cost'=>(string)$unitCost, 'price'=>$columns['price_currency'][$index],
            'unit_price'=>$columns['unit_price'][$index], 'total_cost'=>$lineCost, 'total_price'=>$linePrice,
            'gst_payable'=>$gst, 'tax_pyable'=>'', 'supplier'=>'', 'jas_cost'=>0, 'warehouse'=>'',
            'dropship'=>'Y', 'remarks'=>'', 'notes'=>'', 'pass'=>1,
            'GP'=>$linePrice-$lineCost, 'GP_percent'=>$linePrice == 0.0 ? 0 : (($linePrice-$lineCost)/$linePrice*100),
        ];
    }
    $gstPayable = $totalPrice * $gstRate / 100;
    $gp = $totalPrice - $totalCost;
    return [
        'products'=>$products, 'presales'=>'0', 'quotation_ref'=>(string)$task['po_no'],
        'customer_name'=>(string)$task['customer_name'], 'customer_id'=>(string)$task['customer_id'],
        'user_name'=>(string)$task['end_user_name'], 'so'=>(string)$task['po_no'], 'so1'=>'',
        'customer_payment_term'=>'', 'sales_person'=>(string)$task['sales_person'],
        'customer_address'=>(string)$task['customer_delivery_address'],
        'user_contact'=>(string)$task['end_user_contact'], 'user_mail'=>(string)$task['end_user_email'],
        'delivery_date'=>date('Y-m-d'), 'tax_structure'=>'', 'exchange_rate'=>'1',
        'sales_bundling'=>'Yes', 'date'=>date('Y-m-d'), 'att1'=>null, 'att2'=>null,
        'files'=>[], 'stage'=>0, 'salesman'=>(string)$task['sales_person'],
        'product_amount'=>$totalPrice, 'service_amount'=>null, 'total_price'=>$totalPrice,
        'prior'=>'否', 'grand_total_total_cost'=>$totalCost, 'grand_total_total_price'=>$totalPrice,
        'grand_total_gst_payable'=>$gstPayable, 'grand_total_GP'=>$gp,
        'grand_total_GP_rate'=>$totalPrice == 0.0 ? '0.0%' : number_format($gp/$totalPrice*100, 1, '.', '').'%',
        'total_price_exclude_sst'=>$totalPrice, 'sst_payable'=>$gstPayable,
        'total_price_inclusive_sst'=>$totalPrice+$gstPayable, 'gst_payable_rate'=>$gstRate,
        'total_amount'=>$totalPrice+$gstPayable,
        'buyer_mail'=>(string)$task['buyer_mail'], 'buyer'=>(string)$task['buyer'],
        'buyer_boss'=>(string)$task['buyer_boss'], 'buyer_boss_mail'=>(string)$task['buyer_boss_mail'],
        'applicant_id'=>(string)$task['applicant_id'], 'applicant'=>(string)$task['applicant'],
        'applicant_mail'=>(string)$task['applicant_mail'], 'ratifier'=>(string)$task['ratifier'],
        'ratifier_mail'=>(string)$task['ratifier_mail'],
    ];
}

function submit_task_to_eportal(int $taskId): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('服务器未安装 cURL 扩展。');
    $pdo = db();
    $stmt = $pdo->prepare('SELECT cf.*, u.id AS applicant_id, u.username AS applicant, u.email AS applicant_mail, u.buyer_mail, u.buyer, u.buyer_boss, u.buyer_boss_mail, u.ratifier, u.ratifier_mail FROM contract_forms cf INNER JOIN users u ON u.id=cf.created_by WHERE cf.id=:id LIMIT 1');
    $stmt->execute([':id'=>$taskId]);
    $task = $stmt->fetch();
    if (!$task || (int)$task['status'] !== 8) throw new RuntimeException('任务不是建单中状态。');
    $sheet = dirname(__DIR__) . '/files/costing_sheet/costing_sheet_' . format_task_no($taskId) . '.xlsx';
    if (!is_file($sheet)) throw new RuntimeException('找不到 Costing Sheet。');
    $payload = eportal_payload($task);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('ePortal 数据编码失败。');

    $sheetMime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    $post = ['data'=>$json, 'att2'=>new CURLFile($sheet, $sheetMime, basename($sheet))];
    // cURL 会在发送时把 CURLFile 转成二进制 multipart 段。日志保留完整 data
    // 参数以及每个文件段的名称、类型、大小和来源，避免把二进制文件写入日志。
    $requestBodyLog = [
        'data'=>$json,
        'att2'=>[
            'name'=>basename($sheet),
            'type'=>$sheetMime,
            'size'=>filesize($sheet),
            'path'=>$sheet,
        ],
    ];
    $files = $pdo->prepare('SELECT * FROM contract_form_files WHERE task_id=:id ORDER BY id');
    $files->execute([':id'=>$taskId]);
    foreach ($files->fetchAll() as $index => $file) {
        $path = dirname(__DIR__) . '/files/contract_forms/' . $taskId . '/supplements/' . basename((string)$file['stored_name']);
        if (is_file($path)) {
            $field = 'files[' . $index . ']';
            $post[$field] = new CURLFile($path, (string)$file['mime_type'], (string)$file['original_name']);
            $requestBodyLog[$field] = [
                'name'=>(string)$file['original_name'],
                'type'=>(string)$file['mime_type'],
                'size'=>filesize($path),
                'path'=>$path,
            ];
        }
    }
    $endpoint = (string)config_value('eportal.endpoint');
    $connectTimeout = max(1, (int)config_value('eportal.connect_timeout_seconds', 30));
    $timeout = max($connectTimeout, (int)config_value('eportal.timeout_seconds', 60));
    $headers = ['Accept: application/json','X-Idempotency-Key: '.(string)$task['eportal_request_key']];
    // 保持 data 的原始字段层级，便于从日志直接复制 JSON 与 ePortal 联调。
    write_app_log('eportal', '【请求eportal原格式】', $payload);
    write_app_log('eportal', '【请求eportal multipart】', [
        'task_id'=>$taskId, 'method'=>'POST', 'url'=>$endpoint,
        'content_type'=>'multipart/form-data', 'headers'=>$headers,
        'connect_timeout_seconds'=>$connectTimeout, 'timeout_seconds'=>$timeout,
        'request_body'=>$requestBodyLog,
    ]);

    $curl = curl_init($endpoint);
    curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$post, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>$connectTimeout, CURLOPT_TIMEOUT=>$timeout,
        CURLOPT_HTTPHEADER=>$headers]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $errorNo = curl_errno($curl);
    $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $connectTime = (float)curl_getinfo($curl, CURLINFO_CONNECT_TIME);
    $totalTime = (float)curl_getinfo($curl, CURLINFO_TOTAL_TIME);
    $primaryIp = (string)curl_getinfo($curl, CURLINFO_PRIMARY_IP);
    curl_close($curl);
    // 无论成功、HTTP 错误或连接失败都固定输出该日志，保证一次请求有完整闭环。
    write_app_log('eportal', '【eportal响应】', [
        'task_id'=>$taskId, 'http_status'=>$http, 'primary_ip'=>$primaryIp,
        'connect_time_seconds'=>$connectTime, 'total_time_seconds'=>$totalTime,
        'curl_errno'=>$errorNo, 'curl_error'=>$error,
        'response_body'=>$body === false ? null : (string)$body,
    ]);
    if ($body === false || $error !== '') {
        write_app_log('eportal', '【eportal请求失败】', [
            'task_id'=>$taskId, 'url'=>$endpoint, 'curl_errno'=>$errorNo,
            'curl_error'=>$error ?: '无响应', 'http_status'=>$http,
            'primary_ip'=>$primaryIp, 'connect_time_seconds'=>$connectTime,
            'total_time_seconds'=>$totalTime,
        ]);
        throw new RuntimeException(sprintf(
            'ePortal 请求失败：%s（地址：%s，cURL errno：%d，连接耗时：%.3f 秒，总耗时：%.3f 秒）',
            $error ?: '无响应', $endpoint, $errorNo, $connectTime, $totalTime
        ));
    }
    $response = json_decode((string)$body, true);
    if (!is_array($response) || !eportal_response_is_success($response, $http)) {
        $message = eportal_response_error_message((string)$body, $http);
        throw new RuntimeException('ePortal 建单失败：' . $message);
    }
    return ['response'=>$response, 'raw'=>(string)$body, 'ticket_no'=>eportal_response_ticket_no($response)];
}
