<?php
/**
 * Jalankan / periksa migrasi skema database (idempoten — aman dijalankan berulang).
 *
 *   php cli/migrasi.php           terapkan migrasi yang belum dijalankan
 *   php cli/migrasi.php --periksa periksa & perbaiki SEMUA langkah walau versi skema sudah terbaru
 *                                 (mis. setelah memulihkan database dari backup lama)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require __DIR__ . '/../inc/bootstrap.php';

$paksa = in_array('--periksa', $argv, true);
try {
    $h = jalankan_migrasi($paksa);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migrasi GAGAL: ' . $e->getMessage() . "\n");
    exit(1);
}
printf("Skema database: versi %d -> %d (terbaru %d)\n", $h['dari'], $h['ke'], SKEMA_VERSI);
if (!$h['perubahan']) {
    echo "Tidak ada perubahan: struktur sudah sesuai.\n";
} else {
    foreach ($h['perubahan'] as $v => $ubah) echo "  v$v: " . implode(', ', $ubah) . "\n";
}
