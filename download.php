<?php
require __DIR__ . '/includes/auth.php';
$id=(int)($_GET['id']??0); $requestedType=(string)($_GET['type']??'pdf');
$type=in_array($requestedType,['source','pdf','costing_sheet','supplement'],true)?$requestedType:'pdf';
$stmt=db()->prepare('SELECT * FROM contract_forms WHERE id=:id LIMIT 1');$stmt->execute([':id'=>$id]);$file=$stmt->fetch();
if(!$file){http_response_code(404);exit('文件不存在');}
if(current_user_role()==='submitter'&&(int)$file['created_by']!==(int)$_SESSION['user_id']){http_response_code(403);exit('无权下载该文件');}
if($type==='supplement'){
    $fileId=(int)($_GET['file_id']??0);$extra=db()->prepare('SELECT * FROM contract_form_files WHERE id=:file_id AND task_id=:task_id');$extra->execute([':file_id'=>$fileId,':task_id'=>$id]);$extra=$extra->fetch();
    if(!$extra){http_response_code(404);exit('文件不存在');}
    $download=(string)$extra['original_name'];$path=__DIR__.'/files/contract_forms/'.$id.'/supplements/'.basename((string)$extra['stored_name']);$contentType=(string)$extra['mime_type'];
}elseif($type==='costing_sheet'){
    $download='costing_sheet_'.format_task_no($id).'.xlsx';
    $path=__DIR__.'/files/costing_sheet/'.$download;
    $contentType='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
}else{
    $name=$type==='source'?$file['attachment_source_file']:$file['attachment_contract_quote_epo'];
    $download=$type==='source'?$file['attachment_original_name']:pathinfo($file['attachment_original_name'],PATHINFO_FILENAME).'.pdf';
    $path=__DIR__.'/files/contract_forms/'.$id.'/'.basename((string)$name);
    $contentType=$type==='pdf'?'application/pdf':'application/octet-stream';
    if(!$name){http_response_code(404);exit('文件不存在');}
}
if(!is_file($path)){http_response_code(404);exit('文件不存在');}
header('Content-Type: '.$contentType);header('Content-Length: '.filesize($path));header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($download));header('X-Content-Type-Options: nosniff');readfile($path);exit;
