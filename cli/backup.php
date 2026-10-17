<?php
/**
 * Backup database ParkIt ke file .sql.gz (PHP murni: tidak butuh mysqldump, jalan di cPanel & Windows).
 *
 *   php cli/backup.php                 backup ke config 'backup_dir' (bawaan: folder backup/ di aplikasi)
 *   php cli/backup.php --ke=/path/dir  backup ke folder lain
 *
 * Jadwalkan harian (cron cPanel / Task Scheduler). Simpan backup DI LUAR folder situs bila bisa,
 * dan salin berkala ke tempat lain (Google Drive, komputer kantor). Pulihkan dengan:
 *   gunzip -c parkit-XXXX.sql.gz | mysql -u USER -p NAMA_DB      (atau phpMyAdmin -> Import file .sql.gz)
 * lalu jalankan: php cli/migrasi.php --periksa
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/backup.php';

$ke = null;
foreach ($argv as $a) if (strncmp($a, '--ke=', 5) === 0) $ke = substr($a, 5);
$dir = rtrim($ke ?: (string)config('backup_dir', APP_ROOT . '/backup'), '/\\');
$simpan = max(1, (int)config('backup_simpan', 14));

try {
    $hasil = backup_database($dir, $simpan);
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup GAGAL: ' . $e->getMessage() . "\n");
    try { log_aktivitas(null, 'backup_gagal', null, null, ['error' => $e->getMessage()]); } catch (Throwable $e2) {}
    exit(1);
}
printf("Backup selesai: %s (%.1f KB, %d tabel, %d baris). Disimpan %d backup terakhir.\n",
    $hasil['file'], $hasil['ukuran'] / 1024, $hasil['tabel'], $hasil['baris'], $simpan);

