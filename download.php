<?php
require __DIR__ . '/includes/auth.php';
$id=(int)($_GET['id']??0); $type=($_GET['type']??'pdf')==='source'?'source':'pdf';
$stmt=db()->prepare('SELECT * FROM contract_forms WHERE id=:id LIMIT 1');$stmt->execute([':id'=>$id]);$file=$stmt->fetch();
if(!$file){http_response_code(404);exit('文件不存在');}
$name=$type==='source'?$file['attachment_source_file']:$file['attachment_contract_quote_epo'];
$download=$type==='source'?$file['attachment_original_name']:pathinfo($file['attachment_original_name'],PATHINFO_FILENAME).'.pdf';
$path=__DIR__.'/files/contract_forms/'.$id.'/'.basename((string)$name);if(!$name||!is_file($path)){http_response_code(404);exit('文件不存在');}
header('Content-Type: '.($type==='pdf'?'application/pdf':'application/octet-stream'));header('Content-Length: '.filesize($path));header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($download));header('X-Content-Type-Options: nosniff');readfile($path);exit;
