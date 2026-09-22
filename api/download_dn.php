<?php
require __DIR__ . '/../includes/bootstrap.php';
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT upload_file_name FROM dn_task_pool WHERE id=:id LIMIT 1');
$stmt->execute([':id'=>$id]);
$task = $stmt->fetch();
$path = $task ? dirname(__DIR__).'/files/dn/'.basename((string)$task['upload_file_name']) : '';
if (!$task || !is_file($path)) json_response(['success'=>false,'message'=>'DN PDF 文件不存在。'], 404);
header('Content-Type: application/pdf');
header('Content-Length: '.filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode((string)$task['upload_file_name']));
header('X-Content-Type-Options: nosniff');
header('X-Request-ID: '.(string)($GLOBALS['app_request_id'] ?? ''));
readfile($path);
exit;
