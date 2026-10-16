<?php
/**
 * Tugas pemeliharaan harian. Jadwalkan via Windows Task Scheduler (lihat README):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\parkit-app\cli\maintenance.php
 *
 *  - Menghapus foto kendaraan yang lebih tua dari "retensi_foto_hari" (Pengaturan).
 *  - Menghapus folder tanggal yang sudah kosong.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require __DIR__ . '/../inc/bootstrap.php';

$hari = (int)setting('retensi_foto_hari', '60');
if ($hari <= 0) {
    echo "Retensi foto nonaktif (0 hari). Tidak ada yang dihapus.\n";
    exit(0);
}
$batas = strtotime("-$hari days");
$root = APP_ROOT . '/uploads/foto';
$hapus = 0; $byte = 0;

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    if ($f->isDir()) {
        @rmdir($f->getPathname()); // hanya berhasil bila kosong
        continue;
    }
    if ($f->getFilename() === '.htaccess') continue;
    if ($f->getMTime() < $batas) {
        $byte += $f->getSize();
        if (@unlink($f->getPathname())) $hapus++;
    }
}

log_aktivitas(null, 'retensi_foto', null, null, ['hari' => $hari, 'dihapus' => $hapus, 'mb' => round($byte / 1048576, 1)]);
printf("Retensi %d hari: %d foto dihapus (%.1f MB).\n", $hari, $hapus, $byte / 1048576);
