<?php
require __DIR__ . '/includes/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    redirect('contract_forms.php');
}

$error = '';
$remembered = (string)($_COOKIE['intellisight_mo_username'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = '请输入用户名和密码';
    } else {
        try {
            $stmt = db()->prepare('SELECT id, username, password, name, email, role, status FROM users WHERE username = :username LIMIT 1');
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();
            $verified = false;
            $needsUpgrade = false;

            if ($user && (int)$user['status'] === 1) {
                $stored = (string)$user['password'];
                if (strpos($stored, 'sha256:') === 0) {
                    $verified = hash_equals(substr($stored, 7), hash('sha256', $password));
                    $needsUpgrade = $verified;
                } else {
                    $verified = password_verify($password, $stored);
                }
            }

            if (!$verified) {
                $error = '用户名或密码错误';
            } else {
                if ($needsUpgrade) {
                    $upgrade = db()->prepare('UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id');
                    $upgrade->execute([':password' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['username'] = (string)$user['username'];
                $_SESSION['name'] = (string)($user['name'] ?: $user['username']);
                $_SESSION['email'] = (string)($user['email'] ?? '');
                $_SESSION['role'] = (string)($user['role'] ?? 'submitter');
                if (!empty($_POST['remember'])) {
                    setcookie('intellisight_mo_username', $username, time() + 2592000, app_url('/') ?: '/', '', !empty($_SERVER['HTTPS']), true);
                } else {
                    setcookie('intellisight_mo_username', '', time() - 3600, app_url('/') ?: '/');
                }
                flash('success', '登录成功');
                redirect('contract_forms.php');
            }
        } catch (Throwable $e) {
            write_app_log('login', '登录异常', ['message' => $e->getMessage()]);
            $error = config_value('app.debug', false) ? $e->getMessage() : '系统连接失败，请联系管理员检查数据库配置';
        }
    }
}

$inputUsername = (string)($_POST['username'] ?? $remembered);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>智眸 - 澳门 · 登录</title>
    <style>
        *{box-sizing:border-box}html,body{margin:0;min-height:100%}body{min-height:100vh;overflow:hidden;font-family:"Microsoft YaHei","PingFang SC",Arial,sans-serif;background:linear-gradient(135deg,#edf3ff 0%,#f4f1ff 100%);color:#283247}.login-page{min-height:100vh;display:grid;place-items:center;padding:28px;position:relative}.orb{position:absolute;border-radius:50%;filter:blur(2px);opacity:.55}.orb.a{width:430px;height:430px;background:radial-gradient(circle,#bac8ff 0%,rgba(186,200,255,0) 68%);left:-120px;top:-110px}.orb.b{width:520px;height:520px;background:radial-gradient(circle,#d4c5ff 0%,rgba(212,197,255,0) 70%);right:-150px;bottom:-190px}.grid{position:absolute;inset:0;opacity:.22;background-image:linear-gradient(rgba(78,95,170,.11) 1px,transparent 1px),linear-gradient(90deg,rgba(78,95,170,.11) 1px,transparent 1px);background-size:42px 42px;mask-image:linear-gradient(90deg,#000,transparent 45%,transparent 55%,#000)}.login-shell{width:min(980px,100%);min-height:570px;display:grid;grid-template-columns:1.05fr .95fr;background:rgba(255,255,255,.94);border-radius:24px;box-shadow:0 28px 75px rgba(36,51,94,.18);overflow:hidden;position:relative;z-index:2;border:1px solid rgba(255,255,255,.8)}.visual{padding:48px 52px;color:#fff;background:linear-gradient(145deg,#061f3c 0%,#123d69 56%,#4f54bc 100%);position:relative;overflow:hidden;display:grid;place-items:center}.visual:after{content:"";position:absolute;width:330px;height:330px;border:1px solid rgba(255,255,255,.15);border-radius:50%;right:-115px;bottom:-95px;box-shadow:0 0 0 45px rgba(255,255,255,.035),0 0 0 90px rgba(255,255,255,.025)}.visual-logo{position:relative;z-index:1}.visual-logo img{display:block;width:min(220px,70%);height:auto;margin:auto;filter:drop-shadow(0 16px 26px rgba(0,0,0,.28))}.form-side{display:flex;align-items:center;padding:55px 62px}.form-wrap{width:100%}.form-wrap h2{margin:0 0 9px;font-size:28px}.sub{color:#8a94a6;margin:0 0 32px}.error{padding:11px 13px;border-radius:8px;background:#fff0f2;border:1px solid #ffd7dd;color:#bd3d4d;margin-bottom:18px}.field{margin-bottom:18px}.field label{display:block;margin-bottom:8px;color:#4e596e;font-weight:600}.input{width:100%;height:47px;border:1px solid #dce2eb;border-radius:9px;padding:0 14px;outline:none;color:#283247;background:#fbfcfe}.input:focus{border-color:#6e7fe4;box-shadow:0 0 0 3px rgba(103,115,223,.12);background:#fff}.row{display:flex;justify-content:space-between;align-items:center;margin:4px 0 23px;color:#758095}.remember{display:flex;align-items:center;gap:8px;cursor:pointer}.submit{width:100%;height:48px;border:0;border-radius:9px;color:#fff;font-weight:700;letter-spacing:1px;cursor:pointer;background:linear-gradient(135deg,#4c70e7,#7558e6);box-shadow:0 10px 22px rgba(91,94,220,.25)}.foot{text-align:center;color:#a1a9b6;font-size:11px;margin-top:24px}@media(max-width:760px){body{overflow:auto}.login-page{padding:15px}.login-shell{grid-template-columns:1fr}.visual{display:none}.form-side{padding:45px 28px;min-height:560px}}
    </style>
</head>
<body>
<div class="login-page">
    <div class="orb a"></div><div class="orb b"></div><div class="grid"></div>
    <div class="login-shell">
        <section class="visual">
            <div class="visual-logo"><img src="<?= h(app_url('assets/images/intellisight-ads-logo.png')) ?>" alt="ADS"></div>
        </section>
        <section class="form-side">
            <form class="form-wrap" method="post" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <h2>欢迎登录</h2><p class="sub">请使用智眸·澳门系统账号继续</p>
                <?php if ($error !== ''): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
                <div class="field"><label for="username">用户名</label><input class="input" id="username" name="username" value="<?= h($inputUsername) ?>" required autofocus autocomplete="username" placeholder="请输入用户名"></div>
                <div class="field"><label for="password">密码</label><input class="input" id="password" type="password" name="password" required autocomplete="current-password" placeholder="请输入密码"></div>
                <div class="row"><label class="remember"><input type="checkbox" name="remember" value="1" <?= $remembered !== '' ? 'checked' : '' ?>> 记住用户名</label></div>
                <button class="submit" type="submit">登 录</button>
                <div class="foot">© <?= date('Y') ?> IntelliSight Macau</div>
            </form>
        </section>
    </div>
</div>
</body>
</html>
