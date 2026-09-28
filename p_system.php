<?php
require __DIR__ . '/includes/auth.php';

$pageTitle = 'P系统';
require __DIR__ . '/includes/layout_top.php';
?>
<div class="embedded-system-card">
    <iframe
        class="embedded-system-frame"
        src="http://10.106.4.46:12332/tasks"
        title="P系统任务"
        loading="eager"
        referrerpolicy="no-referrer"
    ></iframe>
</div>
<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
