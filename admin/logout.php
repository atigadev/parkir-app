<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';

if (is_post() && admin()) {
    cek_csrf();
    log_aktivitas(admin_id(), 'logout', null, null, ['via' => 'admin']);
    $_SESSION = [];
    session_destroy();
}
redirect(base_url('admin/login.php'));
