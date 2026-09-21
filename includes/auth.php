<?php
require_once __DIR__ . '/bootstrap.php';

if (empty($_SESSION['user_id'])) {
    $return = $_SERVER['REQUEST_URI'] ?? app_url('contract_forms.php');
    header('Location: ' . app_url('login.php') . '?return=' . rawurlencode($return));
    exit;
}

function require_approver(): void
{
    if (!can_approve_orders()) {
        http_response_code(403);
        exit('无权访问审批订单。');
    }
}
