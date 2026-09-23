<?php

function config_value(string $key, $default = null)
{
    return $default;
}

function split_result_values(string $value): array
{
    return array_map('trim', explode(';', $value));
}

require_once __DIR__ . '/../includes/eportal.php';

function assert_same(string $expected, string $actual, string $case): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf("%s failed\nExpected: %s\nActual: %s\n", $case, $expected, $actual));
        exit(1);
    }
}

assert_same(
    "SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'applicant_id' cannot be null",
    eportal_response_error_message(
        '<!doctype html><h1>SQLSTATE[23000]: Integrity constraint violation: 1048 Column &#039;applicant_id&#039; cannot be null</h1>',
        500
    ),
    'ThinkPHP HTML error'
);
assert_same(
    '参数无效',
    eportal_response_error_message('{"success":false,"message":"\u53c2\u6570\u65e0\u6548"}', 422),
    'JSON business error'
);
assert_same(
    'HTTP 502，响应不是有效 JSON',
    eportal_response_error_message('Bad Gateway', 502),
    'plain HTTP error'
);

$payload = eportal_payload([
    'pid'=>'PC2310270015', 'description'=>'Battery', 'vendor_part_no'=>'HFG3030010',
    'qty'=>'2', 'price_currency'=>'MOP', 'unit_price'=>'3762', 'po_no'=>'8800051419',
    'customer_name'=>'Customer', 'customer_id'=>'MOM140025', 'end_user_name'=>'Receiving',
    'sales_person'=>'Agnes Lao', 'customer_delivery_address'=>'Macau',
    'end_user_contact'=>'853 8881-5400', 'end_user_email'=>'receiving@example.com',
    'buyer_mail'=>'yu.y.zhang@jos.com', 'buyer'=>'Yu y Zhang', 'buyer_boss'=>'Candice Wu',
    'buyer_boss_mail'=>'candice.wu@jos.com', 'applicant_id'=>'349', 'applicant'=>'Icey Liu',
    'applicant_mail'=>'icey.liu@jos.com', 'ratifier'=>'Joan Liu',
    'ratifier_mail'=>'joan.liu@jos.com',
]);
$expectedUserFields = [
    'buyer_mail'=>'yu.y.zhang@jos.com', 'buyer'=>'Yu y Zhang', 'buyer_boss'=>'Candice Wu',
    'buyer_boss_mail'=>'candice.wu@jos.com', 'applicant_id'=>'349', 'applicant'=>'Icey Liu',
    'applicant_mail'=>'icey.liu@jos.com', 'ratifier'=>'Joan Liu',
    'ratifier_mail'=>'joan.liu@jos.com',
];
foreach ($expectedUserFields as $field => $value) {
    assert_same($value, (string)($payload[$field] ?? ''), 'payload field ' . $field);
}

fwrite(STDOUT, "ePortal response tests passed.\n");
