<?php

class TaskFileConverter
{
    /**
     * 不使用 PHP 7.4 才支持的类型化类属性，兼容现有 phpstudy PHP 7.1–7.3 环境。
     * @var array
     */
    private $uploadConfig = [];

    public function __construct(array $uploadConfig)
    {
        $this->uploadConfig = $uploadConfig;
    }

    public function saveAndConvert(array $file, string $taskDir, int $position): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadErrorMessage((int)($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $originalName = trim((string)($file['name'] ?? ''));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = array_map('strtolower', $this->uploadConfig['allowed_extensions'] ?? []);
        if ($originalName === '' || $extension === '' || !in_array($extension, $allowed, true)) {
            throw new RuntimeException('文件“' . $originalName . '”的格式不受支持。');
        }

        $maxBytes = ((int)($this->uploadConfig['max_file_size_mb'] ?? 30)) * 1024 * 1024;
        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > $maxBytes) {
            throw new RuntimeException('文件“' . $originalName . '”为空或超过单文件大小限制。');
        }

        if (!is_dir($taskDir) && !mkdir($taskDir, 0775, true) && !is_dir($taskDir)) {
            throw new RuntimeException('无法创建任务文件目录。');
        }

        $safeStem = $this->safeStem(pathinfo($originalName, PATHINFO_FILENAME));
        $prefix = str_pad((string)$position, 2, '0', STR_PAD_LEFT);
        $sourceName = 'source_' . $prefix . '_' . $safeStem . '.' . $extension;
        $pdfName = 'document_' . $prefix . '_' . $safeStem . '.pdf';
        $sourcePath = $taskDir . DIRECTORY_SEPARATOR . $sourceName;
        $pdfPath = $taskDir . DIRECTORY_SEPARATOR . $pdfName;

        if (!move_uploaded_file((string)$file['tmp_name'], $sourcePath)) {
            throw new RuntimeException('文件“' . $originalName . '”保存失败。');
        }

        try {
            $method = $this->convertToPdf($sourcePath, $pdfPath, $extension);
        } catch (Throwable $e) {
            @unlink($sourcePath);
            @unlink($pdfPath);
            throw $e;
        }

        if (!is_file($pdfPath) || filesize($pdfPath) < 5) {
            throw new RuntimeException('文件“' . $originalName . '”未能生成有效 PDF。');
        }

        $mime = 'application/octet-stream';
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)($finfo->file($sourcePath) ?: $mime);
        }

        return [
            'original_name' => $originalName,
            'stored_name' => $sourceName,
            'pdf_name' => $pdfName,
            'extension' => $extension,
            'mime_type' => $mime,
            'file_size' => $size,
            'sha256' => hash_file('sha256', $sourcePath),
            'conversion_method' => $method,
        ];
    }

    private function convertToPdf(string $sourcePath, string $pdfPath, string $extension): string
    {
        if ($extension === 'pdf') {
            $signature = (string)file_get_contents($sourcePath, false, null, 0, 5);
            if ($signature !== '%PDF-') {
                throw new RuntimeException('上传的 PDF 文件签名无效。');
            }
            if (!copy($sourcePath, $pdfPath)) {
                throw new RuntimeException('复制 PDF 文件失败。');
            }
            return 'copy';
        }

        $imageExtensions = ['jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'webp'];
        if (in_array($extension, $imageExtensions, true)) {
            if ($this->convertImageWithImagick($sourcePath, $pdfPath)) {
                return 'imagick';
            }
            if ($this->convertWithImageMagick($sourcePath, $pdfPath)) {
                return 'imagemagick';
            }
        }

        if ($this->convertWithLibreOffice($sourcePath, $pdfPath)) {
            return 'libreoffice';
        }

        throw new RuntimeException(
            '文件“' . basename($sourcePath) . '”无法转换成 PDF。请安装 LibreOffice；图片也可安装 Imagick 或 ImageMagick。'
        );
    }

    private function convertImageWithImagick(string $sourcePath, string $pdfPath): bool
    {
        if (!class_exists('Imagick')) {
            return false;
        }
        try {
            $image = new Imagick();
            $image->readImage($sourcePath);
            foreach ($image as $frame) {
                if (method_exists($frame, 'autoOrientImage')) {
                    $frame->autoOrientImage();
                }
                $frame->setImageBackgroundColor('white');
                $frame->setImageFormat('pdf');
                $frame->setImageCompressionQuality(88);
            }
            $ok = $image->writeImages($pdfPath, true);
            $image->clear();
            $image->destroy();
            return $ok && is_file($pdfPath);
        } catch (Throwable $e) {
            write_app_log('convert', 'Imagick 转换失败', ['message' => $e->getMessage()]);
            return false;
        }
    }

    private function convertWithImageMagick(string $sourcePath, string $pdfPath): bool
    {
        $binary = $this->findExecutable($this->uploadConfig['imagemagick_paths'] ?? [], ['magick', 'convert']);
        if ($binary === null || !$this->canExec()) {
            return false;
        }
        $command = escapeshellarg($binary) . ' ' . escapeshellarg($sourcePath) . ' -auto-orient -background white -alpha remove ' . escapeshellarg($pdfPath) . ' 2>&1';
        exec($command, $output, $code);
        if ($code !== 0) {
            write_app_log('convert', 'ImageMagick 转换失败', ['code' => $code, 'output' => implode("\n", $output)]);
        }
        return $code === 0 && is_file($pdfPath);
    }

    private function convertWithLibreOffice(string $sourcePath, string $pdfPath): bool
    {
        $binary = $this->findExecutable($this->uploadConfig['libreoffice_paths'] ?? [], ['soffice', 'libreoffice']);
        if ($binary === null || !$this->canExec()) {
            return false;
        }

        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'intellisight_mo_' . bin2hex(random_bytes(6));
        if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            return false;
        }

        $profileDir = $tempDir . DIRECTORY_SEPARATOR . 'profile';
        mkdir($profileDir, 0775, true);
        $profileUrl = 'file:///' . str_replace('\\', '/', $profileDir);
        $command = escapeshellarg($binary)
            . ' --headless --nologo --nodefault --nofirststartwizard'
            . ' -env:UserInstallation=' . escapeshellarg($profileUrl)
            . ' --convert-to pdf --outdir ' . escapeshellarg($tempDir)
            . ' ' . escapeshellarg($sourcePath) . ' 2>&1';
        exec($command, $output, $code);

        $generated = glob($tempDir . DIRECTORY_SEPARATOR . '*.pdf') ?: [];
        $ok = $code === 0 && isset($generated[0]) && is_file($generated[0]) && copy($generated[0], $pdfPath);
        if (!$ok) {
            write_app_log('convert', 'LibreOffice 转换失败', ['code' => $code, 'output' => implode("\n", $output)]);
        }
        $this->removeDirectory($tempDir);
        return $ok;
    }

    private function findExecutable(array $configured, array $commands): ?string
    {
        foreach ($configured as $path) {
            if (is_file($path)) {
                return $path;
            }
        }
        if (!$this->canExec()) {
            return null;
        }
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        foreach ($commands as $command) {
            $lookup = $isWindows ? 'where ' . escapeshellarg($command) : 'command -v ' . escapeshellarg($command);
            exec($lookup . ' 2>&1', $output, $code);
            if ($code === 0 && !empty($output[0])) {
                return trim($output[0]);
            }
        }
        return null;
    }

    private function canExec(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array('exec', $disabled, true);
    }

    private function safeStem(string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}_-]+/u', '_', trim($name));
        $name = trim((string)$name, '_-');
        if ($name === '') {
            $name = 'file';
        }
        return function_exists('mb_substr') ? mb_substr($name, 0, 60, 'UTF-8') : substr($name, 0, 60);
    }

    private function uploadErrorMessage(int $code): string
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE => '文件超过服务器 upload_max_filesize 限制。',
            UPLOAD_ERR_FORM_SIZE => '文件超过页面允许的大小。',
            UPLOAD_ERR_PARTIAL => '文件只上传了一部分。',
            UPLOAD_ERR_NO_FILE => '没有收到上传文件。',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录。',
            UPLOAD_ERR_CANT_WRITE => '服务器无法写入临时文件。',
            UPLOAD_ERR_EXTENSION => 'PHP 扩展终止了文件上传。',
        ];
        return $messages[$code] ?? '文件上传失败，错误码：' . $code;
    }

    public function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
