<?php
require __DIR__ . '/../includes/bootstrap.php';
if(strtoupper($_SERVER['REQUEST_METHOD']??'')!=='POST')json_response(['success'=>false,'message'=>'仅支持 POST。'],405);
$payload=request_payload();
function normalize_result_field($value):string{if(is_array($value))$value=implode(';',array_map('strval',$value));return is_scalar($value)||$value===null?trim((string)$value):'';}
$taskId=(int)($payload['id']??$payload['task_id']??$payload['task_no']??0);
if($taskId<1)json_response(['success'=>false,'message'=>'缺少有效的任务 id。'],422);
$discountRaw=$payload['discount']??null;$discount=($discountRaw===null||$discountRaw==='')?null:normalize_result_field($discountRaw);
if($discount!==null&&!is_numeric($discount))json_response(['success'=>false,'message'=>'discount 必须是数字，也可以是负数。'],422);
$values=[];foreach(['po_no','delivery_address','no','vendor_part_no','description','qty'] as $f)$values[$f]=normalize_result_field($payload[$f]??'');$values['unit_cost']=normalize_result_field($payload['unit_cost']??$payload['unit_price']??'');
$pdo=db();try{$pdo->beginTransaction();$stmt=$pdo->prepare('SELECT id,status FROM contract_forms WHERE id=:id LIMIT 1 FOR UPDATE');$stmt->execute([':id'=>$taskId]);$task=$stmt->fetch();if(!$task){$pdo->rollBack();json_response(['success'=>false,'message'=>'找不到对应任务。'],404);}if(!in_array((int)$task['status'],[3,4,5,6],true)){$pdo->rollBack();json_response(['success'=>false,'message'=>'当前任务状态不能接收识别结果。'],409);}
$sql='UPDATE contract_forms SET po_no=:po_no,delivery_address=:delivery_address,`no`=:no,vendor_part_no=:vendor_part_no,description=:description,qty=:qty,unit_cost=:unit_cost,discount=:discount,raw_result=:raw,status=6,recognition_finished_at=COALESCE(recognition_finished_at,NOW()),matching_started_at=COALESCE(matching_started_at,NOW()),matching_finished_at=COALESCE(matching_finished_at,NOW()),completed_at=COALESCE(completed_at,NOW()),updated_at=NOW() WHERE id=:id';$stmt=$pdo->prepare($sql);$stmt->execute(array_merge($values,[':discount'=>$discount,':raw'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':id'=>$taskId]));$log=$pdo->prepare("INSERT INTO logs (operator,task_id,operation_time,operation_content,task_status) VALUES ('API:returndata',:task_id,NOW(),'识别结果回传',6)");$log->execute([':task_id'=>$taskId]);$pdo->commit();json_response(['success'=>true,'message'=>'识别结果已保存','id'=>$taskId,'status'=>6,'status_text'=>status_label(6)]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['success'=>false,'message'=>'识别结果保存失败','error'=>$e->getMessage()],500);}
