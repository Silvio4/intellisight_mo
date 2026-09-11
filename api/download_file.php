<?php
require __DIR__ . '/../includes/bootstrap.php';
$id=(int)($_GET['id']??$_GET['file_id']??0);
$stmt=db()->prepare('SELECT id, attachment_original_name, attachment_contract_quote_epo FROM contract_forms WHERE id=:id LIMIT 1');
$stmt->execute([':id'=>$id]);$file=$stmt->fetch();
if(!$file){http_response_code(404);header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>false,'message'=>'PDF 文件不存在。'],JSON_UNESCAPED_UNICODE);exit;}
$path=dirname(__DIR__).'/files/contract_forms/'.$id.'/'.basename((string)$file['attachment_contract_quote_epo']);
if(!is_file($path)){http_response_code(404);exit('PDF 文件不存在');}
header('Content-Type: application/pdf');header('Content-Length: '.filesize($path));header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode(pathinfo($file['attachment_original_name'],PATHINFO_FILENAME).'.pdf'));header('X-Content-Type-Options: nosniff');readfile($path);exit;
