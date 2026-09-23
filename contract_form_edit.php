<?php
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/costing_sheet.php';
$id=(int)($_GET['id']??$_POST['id']??0);$pdo=db();
$stmt=$pdo->prepare('SELECT * FROM contract_forms WHERE id=:id');$stmt->execute([':id'=>$id]);$task=$stmt->fetch();
if(!$task){http_response_code(404);exit('任务不存在');}
if((int)$task['status']!==7 || ((int)$task['created_by']!==(int)$_SESSION['user_id'] && current_user_role()!=='admin')){http_response_code(403);exit('仅创建人可以修改已退回订单。');}
$error='';
$fields=['po_no','customer_id','customer_name','customer_delivery_address','end_user_name','end_user_contact','end_user_email','vendor_part_no','description','qty','price_currency','unit_price'];
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$values=[];foreach($fields as $field)$values[$field]=trim((string)($_POST[$field]??''));
 foreach(['vendor_part_no','description','qty','price_currency','unit_price'] as $field)$counts[$field]=count(split_result_values($values[$field]));
 if($values['customer_id']==='')$error='请填写 Customer ID。';
 elseif((function_exists('mb_strlen')?mb_strlen($values['customer_id'],'UTF-8'):strlen($values['customer_id']))>255)$error='Customer ID 最多 255 个字符。';
 elseif(count(array_unique(array_values($counts)))>1)$error='产品明细字段数量必须一致，请使用英文分号分隔。';
 if($error===''){
  try{
   $pdo->beginTransaction();$sets=[];$params=[':id'=>$id];foreach($fields as $field){$sets[]=$field.'=:'.$field;$params[':'.$field]=$values[$field];}
   $sets[]='status=5';$sets[]='rejection_reason=NULL';$sets[]='updated_at=NOW()';
   $update=$pdo->prepare('UPDATE contract_forms SET '.implode(',',$sets).' WHERE id=:id AND status=7');$update->execute($params);
   if($update->rowCount()!==1)throw new RuntimeException('订单状态已变化。');
   $uploads=$_FILES['supplements']??null;
   if($uploads && is_array($uploads['name']??null)){
    $directory=__DIR__.'/files/contract_forms/'.$id.'/supplements';if(!is_dir($directory)&&!mkdir($directory,0775,true)&&!is_dir($directory))throw new RuntimeException('无法创建附件目录。');
    foreach($uploads['name'] as $i=>$original){if(($uploads['error'][$i]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;if(($uploads['error'][$i]??0)!==UPLOAD_ERR_OK)throw new RuntimeException('补充附件上传失败。');
     $size=(int)$uploads['size'][$i];if($size>(int)config_value('upload.max_file_size_mb',30)*1024*1024)throw new RuntimeException('补充附件超过大小限制。');
     $ext=strtolower(pathinfo((string)$original,PATHINFO_EXTENSION));if(!in_array($ext,(array)config_value('upload.allowed_extensions',[]),true))throw new RuntimeException('不支持的补充附件格式。');
     $stored=bin2hex(random_bytes(16)).($ext!==''?'.'.$ext:'');$path=$directory.'/'.$stored;if(!move_uploaded_file($uploads['tmp_name'][$i],$path))throw new RuntimeException('无法保存补充附件。');
     $mime=function_exists('mime_content_type')?(string)mime_content_type($path):'application/octet-stream';
     $pdo->prepare("INSERT INTO contract_form_files(task_id,approval_round,category,original_name,stored_name,mime_type,file_size,sha256,uploaded_by,created_at) SELECT id,approval_round+1,'supplement',:original,:stored,:mime,:size,:sha,:uid,NOW() FROM contract_forms WHERE id=:id")->execute([':original'=>(string)$original,':stored'=>$stored,':mime'=>$mime,':size'=>$size,':sha'=>hash_file('sha256',$path),':uid'=>(int)$_SESSION['user_id'],':id'=>$id]);
    }
   }
   $pdo->prepare("INSERT INTO logs(operator,task_id,operation_time,operation_content,task_status) VALUES(:name,:id,NOW(),'修改退回订单并重新提交',5)")->execute([':name'=>(string)$_SESSION['name'],':id'=>$id]);$pdo->commit();
   generate_costing_sheet($id);flash('success','订单已修改并重新提交审批。');redirect('contract_form_view.php?id='.$id);
  }catch(Throwable $e){
   if($pdo->inTransaction())$pdo->rollBack();
   else $pdo->prepare('UPDATE contract_forms SET status=7,rejection_reason=:reason,updated_at=NOW() WHERE id=:id AND status=5')->execute([':reason'=>(string)$task['rejection_reason'],':id'=>$id]);
   $error=$e->getMessage();
  }
 }
 foreach($values??[] as $field=>$value)$task[$field]=$value;
}
$pageTitle='修改退回订单';require __DIR__ . '/includes/layout_top.php';
?>
<div class="page-head"><div><h2>修改 <?=h(format_task_no($id))?></h2><p>根据退回理由修改资料，重新生成 Costing Sheet 后提交审批</p></div><a class="btn btn-secondary" href="<?=h(app_url('contract_form_view.php?id='.$id))?>">返回详情</a></div>
<div class="alert error"><span>!</span>退回理由：<?=h($task['rejection_reason'])?></div><?php if($error):?><div class="alert error"><span>!</span><?=h($error)?></div><?php endif;?>
<form class="card" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><div class="card-body"><div class="form-grid">
<?php foreach(['po_no'=>'PO No.','customer_id'=>'Customer ID','customer_name'=>'Customer Name','customer_delivery_address'=>'Customer Delivery Address','end_user_name'=>'End User Name','end_user_contact'=>'End User Contact','end_user_email'=>'End User Email'] as $field=>$label):?><div class="form-group <?=$field==='customer_delivery_address'?'full':''?>"><label><?=h($label)?><?=$field==='customer_id'?' <span class="required">*</span>':''?></label><input class="form-control" name="<?=h($field)?>" value="<?=h($task[$field])?>"<?=$field==='customer_id'?' maxlength="255" required':''?>></div><?php endforeach;?>
<?php foreach(['vendor_part_no'=>'Vendor Part No.','description'=>'Description','qty'=>'Qty','price_currency'=>'Price Currency','unit_price'=>'Unit Price'] as $field=>$label):?><div class="form-group"><label><?=h($label)?>（英文分号分隔）</label><textarea class="form-control" name="<?=h($field)?>" rows="3"><?=h($task[$field])?></textarea></div><?php endforeach;?>
<div class="form-group full"><label>补充附件</label><input class="form-control" type="file" name="supplements[]" multiple><div class="form-help">可选；会保留原文件和历次补充附件。</div></div></div><div class="form-actions"><button class="btn btn-primary">重新生成并提交审批</button></div></div></form>
<?php require __DIR__ . '/includes/layout_bottom.php';?>
