<?php
/**
 * Menyajikan foto kendaraan lewat URL bertanda tangan & kedaluwarsa (lihat url_foto_aman()).
 * Folder uploads/foto tertutup dari akses langsung, karena plat + foto kendaraan adalah data pribadi.
 */
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
header_keamanan('file');

$path = (string)($_GET['f'] ?? '');
$exp = (int)($_GET['e'] ?? 0);
$tanda = (string)($_GET['s'] ?? '');

if (!preg_match('#^uploads/foto/\d{4}/\d{2}/\d{2}/(in|out)_[a-f0-9]{16}\.(jpg|png|webp)$#', $path)
    || !preg_match('/^[a-f0-9]{32}$/', $tanda)
    || !hash_equals(tanda_foto($path, $exp), $tanda)) {
    http_response_code(403);
    exit('Tautan foto tidak sah.');
}
if ($exp < time()) {
    http_response_code(410);
    exit('Tautan foto sudah kedaluwarsa. Muat ulang halaman.');
}
$file = APP_ROOT . '/' . $path;
if (!is_file($file)) {
    http_response_code(404);
    exit('Foto tidak ditemukan (mungkin sudah dihapus oleh retensi foto).');
}

$tipe = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($file, PATHINFO_EXTENSION)];
header('Content-Type: ' . $tipe);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=' . max(0, $exp - time()));
readfile($file);
