<?php

function config_value(string $key, $default = null)
{
    global $config;
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $base = rtrim((string)config_value('app.base_path', '/intellisight_mo'), '/');
    return $base . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function absolute_app_url(string $path = ''): string
{
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $https = $forwardedProto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $forwardedHost = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
    $host = $forwardedHost !== '' ? $forwardedHost : ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . app_url($path);
}

function redirect(string $path): void
{
    header('Location: ' . app_url($path));
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $given = (string)($_POST['csrf_token'] ?? '');
    if ($given === '' || !hash_equals(csrf_token(), $given)) {
        http_response_code(419);
        exit('页面已过期，请返回刷新后重试。');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($value) ? $value : null;
}

function status_label(string $status): string
{
    $map = [
        'pending' => '待识别',
        'recognizing' => '识别中',
        'completed' => '已完成',
        'failed' => '识别失败',
    ];
    return $map[$status] ?? $status;
}

function status_class(string $status): string
{
    $map = [
        'pending' => 'pending',
        'recognizing' => 'recognizing',
        'completed' => 'completed',
        'failed' => 'failed',
    ];
    return $map[$status] ?? 'pending';
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_payload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '', true);
        if (!is_array($data)) {
            json_response(['success' => false, 'code' => 'invalid_json', 'message' => '请求体不是有效 JSON。'], 400);
        }
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }
        return $data;
    }
    return $_POST;
}

function write_app_log(string $channel, string $message, array $context = []): void
{
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $safeChannel = preg_replace('/[^a-z0-9_-]/i', '_', $channel);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($dir . '/' . $safeChannel . '_' . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function split_result_values(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }
    return array_map('trim', explode(';', $value));
}

function remove_directory_safe(string $directory): void
{
    $root = realpath(__DIR__ . '/../files');
    $target = realpath($directory);
    if ($root === false || $target === false || strpos($target, $root . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }
    $items = array_diff(scandir($target) ?: [], ['.', '..']);
    foreach ($items as $item) {
        $path = $target . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? remove_directory_safe($path) : @unlink($path);
    }
    @rmdir($target);
}
