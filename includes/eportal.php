<?php

function eportal_payload(array $task): array
{
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
        'customer_name'=>(string)$task['customer_name'], 'customer_id'=>'',
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
        'total_amount'=>$totalPrice+$gstPayable, 'buyer_mail'=>null, 'buyer'=>null,
    ];
}

function submit_task_to_eportal(int $taskId): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('服务器未安装 cURL 扩展。');
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM contract_forms WHERE id=:id LIMIT 1');
    $stmt->execute([':id'=>$taskId]);
    $task = $stmt->fetch();
    if (!$task || (int)$task['status'] !== 8) throw new RuntimeException('任务不是建单中状态。');
    $sheet = dirname(__DIR__) . '/files/costing_sheet/costing_sheet_' . format_task_no($taskId) . '.xlsx';
    if (!is_file($sheet)) throw new RuntimeException('找不到 Costing Sheet。');
    $payload = eportal_payload($task);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('ePortal 数据编码失败。');

    $post = ['data'=>$json, 'att2'=>new CURLFile($sheet, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', basename($sheet))];
    $files = $pdo->prepare('SELECT * FROM contract_form_files WHERE task_id=:id ORDER BY id');
    $files->execute([':id'=>$taskId]);
    foreach ($files->fetchAll() as $index => $file) {
        $path = dirname(__DIR__) . '/files/contract_forms/' . $taskId . '/supplements/' . basename((string)$file['stored_name']);
        if (is_file($path)) $post['files[' . $index . ']'] = new CURLFile($path, (string)$file['mime_type'], (string)$file['original_name']);
    }
    $curl = curl_init((string)config_value('eportal.endpoint'));
    curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$post, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>10, CURLOPT_TIMEOUT=>max(1,(int)config_value('eportal.timeout_seconds',30)),
        CURLOPT_HTTPHEADER=>['Accept: application/json','X-Idempotency-Key: '.(string)$task['eportal_request_key']]]);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($body === false || $error !== '') throw new RuntimeException('ePortal 请求失败：' . ($error ?: '无响应'));
    $response = json_decode((string)$body, true);
    if ($http < 200 || $http >= 300 || !is_array($response) || ($response['success'] ?? false) !== true) {
        $message = is_array($response) ? (string)($response['message'] ?? '未确认建单成功') : '响应不是有效 JSON';
        throw new RuntimeException('ePortal 建单失败：' . $message);
    }
    return ['response'=>$response, 'raw'=>(string)$body, 'ticket_no'=>(string)($response['ticket_no'] ?? $response['id'] ?? '')];
}
