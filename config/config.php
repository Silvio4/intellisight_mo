<?php
/**
 * 智眸 - 澳门 配置文件
 * 部署后请修改数据库信息。
 */
return [
    'app' => [
        'name' => '智眸 - 澳门',
        'base_path' => '/intellisight_mo',
        'timezone' => 'Asia/Macau',
        'session_name' => 'INTELLISIGHT_MO_SESSION',
        'debug' => false,
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'intellisight_mo',
        'username' => 'root',
        'password' => 'root',
        'charset' => 'utf8mb4',
    ],
    'api' => [
        // 允许 get_task.php?include_base64=1 在 JSON 中直接携带 PDF；大文件建议使用 download_url。
        'allow_base64' => true,
    ],
    'p_system' => [
        // 可通过环境变量覆盖地址；P_SYS_PORT 与 P 系统服务端的配置同名。
        'endpoint' => getenv('P_SYS_ENDPOINT') ?: sprintf(
            'http://10.106.4.46:%s/api/tasks',
            getenv('P_SYS_PORT') ?: '12332'
        ),
        'timeout_seconds' => 10,
    ],
    'eportal' => [
        'endpoint' => getenv('EPORTAL_ENDPOINT') ?: 'http://10.106.4.174/mo.php/api/createTicket',
        'timeout_seconds' => (int)(getenv('EPORTAL_TIMEOUT_SECONDS') ?: 30),
        'node_id' => 'JOSM',
        'biz_category' => 'Product',
        'gst_rate' => 7,
    ],
    'upload' => [
        'max_files' => 10,
        'max_file_size_mb' => 30,
        'allowed_extensions' => [
            'pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'bmp', 'webp',
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'rtf', 'txt', 'csv',
        ],
        // 系统会依次尝试这些位置，也会尝试 PATH 中的 soffice/libreoffice。
        'libreoffice_paths' => [
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe',
            '/usr/bin/libreoffice',
            '/usr/bin/soffice',
        ],
        // ImageMagick 可选；未安装时会尝试 Imagick PHP 扩展或 LibreOffice。
        'imagemagick_paths' => [
            'C:\\Program Files\\ImageMagick-7.1.1-Q16-HDRI\\magick.exe',
            '/usr/bin/magick',
            '/usr/bin/convert',
        ],
    ],
];
