<?php
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/file_converter.php';
$pageTitle = '新建合同表单';
$error = '';
$salesPerson = trim((string)($_POST['sales_person'] ?? ''));
$customerId = trim((string)($_POST['customer_id'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $file = $_FILES['document'] ?? [];
    if ($customerId === '') {
        $error = '请填写 Customer ID。';
    } elseif ((function_exists('mb_strlen') ? mb_strlen($customerId, 'UTF-8') : strlen($customerId)) > 255) {
        $error = 'Customer ID 最多 255 个字符。';
    } elseif ($salesPerson === '') {
        $error = '请填写 Sales Person。';
    } elseif ((function_exists('mb_strlen') ? mb_strlen($salesPerson, 'UTF-8') : strlen($salesPerson)) > 120) {
        $error = 'Sales Person 最多 120 个字符。';
    } elseif (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error = '请上传一份合同资料。';
    } else {
        $pdo = db();
        $converter = new TaskFileConverter((array)config_value('upload', []));
        $taskDir = '';
        try {
            $pdo->beginTransaction();
            $operator = (string)($_SESSION['name'] ?? $_SESSION['username'] ?? '');
            $stmt = $pdo->prepare('INSERT INTO contract_forms (created_by, created_by_name, created_by_mail, customer_id, sales_person, status, created_at, submitted_recognition_at, updated_at) VALUES (:user_id, :name, :mail, :customer_id, :sales_person, 1, NOW(), NULL, NOW())');
            $stmt->execute([':user_id' => (int)$_SESSION['user_id'], ':name' => $operator, ':mail' => (string)($_SESSION['email'] ?? ''), ':customer_id' => $customerId, ':sales_person' => $salesPerson]);
            $taskId = (int)$pdo->lastInsertId();
            $insertLog = $pdo->prepare('INSERT INTO logs (operator, task_id, operation_time, operation_content, task_status) VALUES (:operator, :task_id, NOW(), :content, :status)');
            $insertLog->execute([':operator' => $operator, ':task_id' => $taskId, ':content' => '新建表单', ':status' => 1]);
            $taskDir = __DIR__ . '/files/contract_forms/' . $taskId;
            $saved = $converter->saveAndConvert($file, $taskDir, 1);
            $update = $pdo->prepare('UPDATE contract_forms SET status = 2, attachment_original_name = :original, attachment_source_file = :source, attachment_contract_quote_epo = :pdf, attachment_extension = :extension, attachment_file_size = :size, attachment_sha256 = :sha256, submitted_recognition_at = NOW(), updated_at = NOW() WHERE id = :id');
            $update->execute([':original'=>$saved['original_name'], ':source'=>$saved['stored_name'], ':pdf'=>$saved['pdf_name'], ':extension'=>$saved['extension'], ':size'=>$saved['file_size'], ':sha256'=>$saved['sha256'], ':id'=>$taskId]);
            $insertLog->execute([':operator' => $operator, ':task_id' => $taskId, ':content' => '保存表单', ':status' => 1]);
            $insertLog->execute([':operator' => $operator, ':task_id' => $taskId, ':content' => '提交识别', ':status' => 2]);
            $pdo->commit();
            flash('success', '合同任务 ' . format_task_no($taskId) . ' 已创建，当前状态为“待识别”。');
            redirect('contract_form_view.php?id=' . $taskId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($taskDir !== '') $converter->removeDirectory($taskDir);
            write_app_log('upload', '创建任务失败', ['message'=>$e->getMessage()]);
            $error = $e->getMessage();
        }
    }
}
require __DIR__ . '/includes/layout_top.php';
?>
<div class="page-head"><div><h2>新建合同表单</h2><p>上传合同资料，系统将自动生成任务号</p></div><a class="btn btn-secondary" href="<?= h(app_url('contract_forms.php')) ?>">← 返回列表</a></div>
<?php if ($error !== ''): ?><div class="alert error"><span>!</span><?= h($error) ?></div><?php endif; ?>
<form class="card" method="post" enctype="multipart/form-data" data-task-form>
<input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
<div class="card-head"><h3>合同资料</h3><span style="color:#929bab">带 <i class="required">*</i> 为必填项</span></div>
<div class="card-body"><div class="form-grid"><div class="form-group"><label for="customer_id">Customer ID <span class="required">*</span></label><input class="form-control" id="customer_id" name="customer_id" value="<?= h($customerId) ?>" maxlength="255" required placeholder="请输入 Customer ID"></div><div class="form-group"><label for="sales_person">Sales Person <span class="required">*</span></label><input class="form-control" id="sales_person" name="sales_person" value="<?= h($salesPerson) ?>" maxlength="120" required placeholder="请输入 Sales Person"></div><div class="form-group full"><label>合同资料 <span class="required">*</span></label><div class="upload-zone" data-upload-zone><input type="file" name="document" required data-file-input accept=".pdf,.jpg,.jpeg,.png,.tif,.tiff,.bmp,.webp,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.rtf,.txt,.csv"><div class="upload-icon">⇧</div><strong>点击选择文件，或将文件拖到这里</strong><small>支持 PDF、图片及 Office 文档；单份不超过 <?= (int)config_value('upload.max_file_size_mb', 30) ?> MB，系统会转换成识别用 PDF。</small></div><div class="file-list" data-file-list></div></div></div><div class="form-actions"><a class="btn btn-secondary" href="<?= h(app_url('contract_forms.php')) ?>">取消</a><button class="btn btn-primary" type="submit">提交并生成任务</button></div></div>
</form>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
