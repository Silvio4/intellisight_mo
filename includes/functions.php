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

function status_label($status): string
{
    $map = [
        1 => '草稿中', 2 => '待识别', 3 => '识别中', 4 => '匹配中', 5 => '待建表',
        6 => '待审批', 7 => '已退回', 8 => '建单中', 9 => '已完成', 10 => '待重试',
    ];
    return $map[$status] ?? $status;
}

function status_class($status): string
{
    $map = [
        1 => 'pending', 2 => 'pending', 3 => 'recognizing', 4 => 'recognizing', 5 => 'pending',
        6 => 'pending', 7 => 'failed', 8 => 'recognizing', 9 => 'completed', 10 => 'failed',
    ];
    return $map[$status] ?? 'pending';
}

function current_user_role(): string
{
    $role = (string)($_SESSION['role'] ?? 'submitter');
    return in_array($role, ['submitter', 'approver', 'admin'], true) ? $role : 'submitter';
}

function can_approve_orders(): bool
{
    return in_array(current_user_role(), ['approver', 'admin'], true);
}

function format_task_no($id): string
{
    return 'T' . str_pad((string)(int)$id, 6, '0', STR_PAD_LEFT);
}

function parse_task_id($value): int
{
    $value = strtoupper(trim((string)$value));
    if (preg_match('/^T?(\d+)$/', $value, $matches)) {
        return (int)$matches[1];
    }
    return 0;
}

function json_response(array $data, int $status = 200): void
{
    app_request_log('response', [
        'http_status' => $status,
        'success' => $data['success'] ?? null,
        'code' => $data['code'] ?? null,
        'task_id' => $data['task_id'] ?? ($data['task']['task_id'] ?? null),
        'has_task' => $data['has_task'] ?? null,
        'recognizing_task_id' => $data['recognizing_task_id'] ?? null,
    ]);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Request-ID: ' . (string)($GLOBALS['app_request_id'] ?? ''));
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Write a lifecycle event for the current request. Potentially sensitive or very
 * large request values are deliberately not recorded; detailed business stages
 * should add their own small, diagnostic context.
 */
function app_request_log(string $stage, array $context = []): void
{
    $isApi = strpos(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/api/') !== false;
    $base = [
        'request_id' => $GLOBALS['app_request_id'] ?? null,
        'stage' => $stage,
        'method' => $_SERVER['REQUEST_METHOD'] ?? PHP_SAPI,
        'path' => parse_url((string)($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? 'cli')), PHP_URL_PATH),
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    ];
    write_app_log($isApi ? 'api' : 'app', 'request.' . $stage, array_merge($base, $context));
}

function initialize_request_logging(): void
{
    if (isset($GLOBALS['app_request_id'])) {
        return;
    }
    try {
        $GLOBALS['app_request_id'] = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $GLOBALS['app_request_id'] = uniqid('', true);
    }
    $GLOBALS['app_request_started_at'] = microtime(true);
    app_request_log('started', ['query_keys' => array_keys($_GET ?? [])]);

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if ($error && in_array($error['type'], $fatalTypes, true)) {
            app_request_log('fatal', [
                'error' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
            ]);
        }
        app_request_log('finished', [
            'http_status' => http_response_code(),
            'duration_ms' => (int)round((microtime(true) - ($GLOBALS['app_request_started_at'] ?? microtime(true))) * 1000),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ]);
    });
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
    $isApi = strpos(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/api/') !== false;
    $dir = $isApi ? __DIR__ . '/../api/logs' : __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $scriptChannel = pathinfo((string)($_SERVER['SCRIPT_FILENAME'] ?? ''), PATHINFO_FILENAME);
    $safeChannel = preg_replace('/[^a-z0-9_-]/i', '_', $isApi && $scriptChannel !== '' ? $scriptChannel : $channel);
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
