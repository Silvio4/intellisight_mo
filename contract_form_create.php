<?php
require __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/file_converter.php';

$pageTitle = '新建合同表单';
$error = '';
$salesPerson = trim((string)($_POST['sales_person'] ?? ''));

function normalize_uploads(array $files): array
{
    $normalized = [];
    if (!isset($files['name']) || !is_array($files['name'])) {
        return $normalized;
    }
    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
    }
    return $normalized;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadNames = $_FILES['documents']['name'] ?? [];
    app_request_log('task.validation_started', ['upload_count' => is_array($uploadNames) ? count($uploadNames) : 0]);
    verify_csrf();
    $uploadedFiles = normalize_uploads($_FILES['documents'] ?? []);
    $maxFiles = (int)config_value('upload.max_files', 10);

    if ($salesPerson === '') {
        $error = '请填写 Sales Person。';
    } elseif (function_exists('mb_strlen') ? mb_strlen($salesPerson, 'UTF-8') > 120 : strlen($salesPerson) > 120) {
        $error = 'Sales Person 最多 120 个字符。';
    } elseif (count($uploadedFiles) < 1 || count($uploadedFiles) > $maxFiles) {
        $error = '请上传 1 至 ' . $maxFiles . ' 份文件。';
    } else {
        $pdo = db();
        $converter = new TaskFileConverter((array)config_value('upload', []));
        $taskDir = '';
        try {
            app_request_log('task.transaction_started');
            $pdo->beginTransaction();
            $insertTask = $pdo->prepare("INSERT INTO contract_tasks (task_no, sales_person, status, created_by, created_at, updated_at) VALUES (NULL, :sales_person, 'pending', :created_by, NOW(), NOW())");
            $insertTask->execute([':sales_person' => $salesPerson, ':created_by' => (int)$_SESSION['user_id']]);
            $taskId = (int)$pdo->lastInsertId();
            $taskNo = format_task_no($taskId);
            $updateTask = $pdo->prepare('UPDATE contract_tasks SET task_no = :task_no WHERE id = :id');
            $updateTask->execute([':task_no' => $taskNo, ':id' => $taskId]);

            $taskDir = __DIR__ . '/files/' . $taskNo;
            $insertFile = $pdo->prepare(
                'INSERT INTO contract_task_files
                    (task_id, original_name, stored_name, pdf_name, extension, mime_type, file_size, sha256, conversion_status, conversion_method, created_at)
                 VALUES
                    (:task_id, :original_name, :stored_name, :pdf_name, :extension, :mime_type, :file_size, :sha256, \'success\', :conversion_method, NOW())'
            );

            foreach ($uploadedFiles as $index => $file) {
                app_request_log('task.file_conversion_started', ['task_no' => $taskNo, 'file_index' => $index + 1, 'original_name' => basename((string)$file['name'])]);
                $saved = $converter->saveAndConvert($file, $taskDir, $index + 1);
                $saved['task_id'] = $taskId;
                $insertFile->execute([
                    ':task_id' => $taskId,
                    ':original_name' => $saved['original_name'],
                    ':stored_name' => $saved['stored_name'],
                    ':pdf_name' => $saved['pdf_name'],
                    ':extension' => $saved['extension'],
                    ':mime_type' => $saved['mime_type'],
                    ':file_size' => $saved['file_size'],
                    ':sha256' => $saved['sha256'],
                    ':conversion_method' => $saved['conversion_method'],
                ]);
                app_request_log('task.file_saved', ['task_no' => $taskNo, 'file_index' => $index + 1, 'conversion_method' => $saved['conversion_method']]);
            }
            $pdo->commit();
            app_request_log('task.created', ['task_no' => $taskNo, 'task_id' => $taskId, 'file_count' => count($uploadedFiles)]);
            flash('success', '合同任务 ' . $taskNo . ' 已创建，当前状态为“待识别”。');
            redirect('contract_form_detail.php?task_no=' . rawurlencode($taskNo));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($taskDir !== '') {
                $converter->removeDirectory($taskDir);
            }
            write_app_log('upload', '创建任务失败', ['message' => $e->getMessage()]);
            app_request_log('task.failed', ['task_no' => $taskNo ?? null, 'error' => $e->getMessage()]);
            $error = $e->getMessage();
        }
    }
    if ($error !== '') {
        app_request_log('task.validation_failed', ['error' => $error]);
    }
}

require __DIR__ . '/includes/layout_top.php';
?>
<div class="page-head">
    <div><h2>新建合同表单</h2><p>填写销售人员并上传 1–10 份合同相关资料</p></div>
    <a class="btn btn-secondary" href="<?= h(app_url('contract_forms.php')) ?>">← 返回列表</a>
</div>

<?php if ($error !== ''): ?><div class="alert error"><span>!</span><?= h($error) ?></div><?php endif; ?>

<form class="card" method="post" enctype="multipart/form-data" data-task-form>
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <div class="card-head"><h3>基本信息</h3><span style="color:#929bab">带 <i class="required">*</i> 为必填项</span></div>
    <div class="card-body">
        <div class="form-grid">
            <div class="form-group">
                <label for="sales_person">Sales Person <span class="required">*</span></label>
                <input class="form-control" id="sales_person" name="sales_person" value="<?= h($salesPerson) ?>" maxlength="120" required placeholder="请输入 Sales Person">
                <div class="form-help">用于标识此合同任务对应的销售人员。</div>
            </div>
            <div class="form-group full">
                <label>合同资料 <span class="required">*</span> <span style="float:right;color:#929bab;font-weight:400" data-file-count>0 / 10</span></label>
                <div class="upload-zone" data-upload-zone>
                    <input type="file" name="documents[]" multiple required data-file-input accept=".pdf,.jpg,.jpeg,.png,.tif,.tiff,.bmp,.webp,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.rtf,.txt,.csv">
                    <div class="upload-icon">⇧</div>
                    <strong>点击选择文件，或将文件拖到这里</strong>
                    <small>支持 PDF、图片、Word、Excel、PowerPoint、RTF、TXT、CSV；最多 10 份，单份不超过 <?= (int)config_value('upload.max_file_size_mb', 30) ?> MB。<br>非 PDF 文件提交后会自动转换为 PDF，供识别接口读取。</small>
                </div>
                <div class="file-list" data-file-list></div>
            </div>
        </div>
        <div class="form-actions">
            <a class="btn btn-secondary" href="<?= h(app_url('contract_forms.php')) ?>">取消</a>
            <button class="btn btn-primary" type="submit">提交并生成任务</button>
        </div>
    </div>
</form>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
