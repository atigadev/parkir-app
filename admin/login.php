<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';

if (admin()) redirect(base_url('admin/'));

$error = '';
if (is_post()) {
    cek_csrf();
    $u = trim((string)($_POST['username'] ?? ''));
    $p = q_one('SELECT * FROM petugas WHERE username = ?', [$u]);
    if ($p && password_verify((string)($_POST['password'] ?? ''), $p['password_hash']) && $p['aktif'] && $p['role'] === 'admin') {
        session_regenerate_id(true);
        $_SESSION['admin'] = ['id' => (int)$p['id'], 'nama' => $p['nama'], 'username' => $p['username']];
        q('UPDATE petugas SET last_login = ? WHERE id = ?', [now(), $p['id']]);
        log_aktivitas((int)$p['id'], 'login', null, null, ['via' => 'admin']);
        redirect(base_url('admin/'));
    }
    log_aktivitas($p ? (int)$p['id'] : null, 'login_gagal', null, null, ['username' => $u, 'via' => 'admin']);
    usleep(400000);
    $error = $p && $p['role'] !== 'admin' && password_verify((string)($_POST['password'] ?? ''), $p['password_hash'])
        ? 'Akun petugas tidak memiliki akses admin. Gunakan Aplikasi Petugas.'
        : 'Username atau password salah.';
}
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk · ParkIt Admin</title>
<link rel="icon" href="<?= e(base_url('pwa/icons/icon.svg')) ?>">
<link rel="stylesheet" href="<?= e(base_url('admin/assets/admin.css')) ?>?v=1">
</head>
<body>
<div class="login-wrap">
  <form class="card login" method="post" autocomplete="on">
    <div class="card-b">
      <div class="brand"><span class="logo">P</span><div><b>ParkIt Admin</b><small class="muted"><?= e(setting('nama_lokasi')) ?></small></div></div>
      <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
      <?= csrf_field() ?>
      <label class="f">Username<input name="username" required autofocus value="<?= e($_POST['username'] ?? '') ?>"></label>
      <label class="f">Password<input name="password" type="password" required></label>
      <button class="btn" style="justify-content:center;padding:10px">Masuk</button>
      <a class="muted" href="<?= e(base_url('pwa/')) ?>" style="text-align:center">Petugas pos? Buka Aplikasi Petugas →</a>
    </div>
  </form>
</div>
</body>
</html>
