<?php
if (!isset($pageTitle)) {
    $pageTitle = '合同表单';
}
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$displayName = trim((string)($_SESSION['name'] ?? '')) ?: (string)($_SESSION['username'] ?? '用户');
$avatarText = function_exists('mb_substr') ? mb_substr($displayName, 0, 1, 'UTF-8') : substr($displayName, 0, 1);
$flashMessage = take_flash();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> - 智眸·澳门</title>
    <link rel="stylesheet" href="<?= h(app_url('assets/css/app.css')) ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= h(app_url('contract_forms.php')) ?>">
            <img class="brand-logo" src="<?= h(app_url('assets/images/intellisight-ads-logo.png')) ?>" alt="ADS">
        </a>
        <nav class="nav-menu">
            <a class="nav-item <?= in_array($currentPage, ['contract_forms.php', 'contract_form_create.php', 'contract_form_view.php'], true) ? 'active' : '' ?>" href="<?= h(app_url('contract_forms.php')) ?>">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6z"/><path d="M15 3v5h5M9 12h7M9 16h7"/></svg>
                </span>
                <span class="nav-text">合同表单</span>
            </a>
        </nav>
        <div class="sidebar-foot"><span class="nav-text">智眸·澳门 v0.3</span></div>
    </aside>

    <main class="main-area">
        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-label="收起侧栏">
                    <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </button>
                <div>
                    <h1><?= h($pageTitle) ?></h1>
                    <p>IntelliSight Macau Contract Workspace</p>
                </div>
            </div>
            <div class="user-menu">
                <span class="welcome">欢迎，<?= h($displayName) ?></span>
                <span class="avatar"><?= h($avatarText) ?></span>
                <a class="logout-link" href="<?= h(app_url('logout.php')) ?>">退出</a>
            </div>
        </header>

        <div class="page-content">
            <?php if ($flashMessage): ?>
                <div class="alert <?= h($flashMessage['type']) ?>">
                    <span><?= $flashMessage['type'] === 'success' ? '✓' : '!' ?></span>
                    <?= h($flashMessage['message']) ?>
                </div>
            <?php endif; ?>
