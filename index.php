<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
header_keamanan('admin');
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ParkIt</title>
<link rel="icon" href="pwa/icons/icon.svg">
<link rel="stylesheet" href="admin/assets/admin.css?v=4">
<style>
  .pick { display: grid; gap: 14px; max-width: 420px; width: 100%; }
  .pick a { display: flex; gap: 14px; align-items: center; padding: 18px; text-decoration: none; color: var(--text); }
  .pick a:hover { border-color: var(--accent); }
  .pick .logo { width: 44px; height: 44px; }
  .pick b { display: block; font-size: 16px; }
</style>
</head>
<body>
<div class="login-wrap">
  <div class="pick">
    <div class="brand" style="padding:0 0 8px"><span class="logo">P</span><div><b style="color:var(--text)">ParkIt</b><small class="muted"><?= e(setting('nama_lokasi')) ?></small></div></div>
    <a class="card" href="pwa/"><span class="logo" style="background:var(--ok)">📱</span><span><b>Aplikasi Petugas</b><span class="muted">Pos masuk & pos keluar di smartphone</span></span></a>
    <a class="card" href="admin/"><span class="logo">⚙</span><span><b>Panel Admin</b><span class="muted">Dashboard, laporan, tarif, petugas</span></span></a>
  </div>
</div>
</body>
</html>
