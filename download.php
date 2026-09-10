<?php
require __DIR__ . '/includes/auth.php';

$fileId = (int)($_GET['file_id'] ?? 0);
$type = ($_GET['type'] ?? 'pdf') === 'source' ? 'source' : 'pdf';
$stmt = db()->prepare(
    'SELECT f.*, t.task_no FROM contract_task_files f JOIN contract_tasks t ON t.id = f.task_id WHERE f.id = :id LIMIT 1'
);
$stmt->execute([':id' => $fileId]);
$file = $stmt->fetch();
if (!$file) {
    http_response_code(404);
    exit('文件不存在');
}

$storedName = $type === 'source' ? $file['stored_name'] : $file['pdf_name'];
$downloadName = $type === 'source' ? $file['original_name'] : pathinfo($file['original_name'], PATHINFO_FILENAME) . '.pdf';
$path = __DIR__ . '/files/' . $file['task_no'] . '/' . basename($storedName);
if (!is_file($path)) {
    http_response_code(404);
    exit('文件不存在');
}

header('Content-Type: ' . ($type === 'pdf' ? 'application/pdf' : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
