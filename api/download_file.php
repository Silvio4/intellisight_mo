<?php
require __DIR__ . '/../includes/bootstrap.php';

$fileId = (int)($_GET['file_id'] ?? 0);
$token = trim((string)($_GET['token'] ?? ''));

$stmt = db()->prepare(
    'SELECT f.id, f.pdf_name, f.original_name, t.task_no, t.claim_token, t.status
       FROM contract_task_files f
       JOIN contract_tasks t ON t.id = f.task_id
      WHERE f.id = :id AND f.conversion_status = \'success\' LIMIT 1'
);
$stmt->execute([':id' => $fileId]);
$file = $stmt->fetch();

$validToken = $file && $token !== '' && !empty($file['claim_token']) && hash_equals((string)$file['claim_token'], $token);
if (!$file || !$validToken) {
    http_response_code($file ? 401 : 404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => $file ? 'PDF 下载令牌无效。' : 'PDF 文件不存在。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$path = dirname(__DIR__) . '/files/' . $file['task_no'] . '/' . basename($file['pdf_name']);
if (!is_file($path)) {
    http_response_code(404);
    exit('PDF 文件不存在');
}

$downloadName = pathinfo($file['original_name'], PATHINFO_FILENAME) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
