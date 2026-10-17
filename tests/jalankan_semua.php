<?php
/**
 * Jalankan semua uji otomatis sekaligus. Wajib lulus sebelum deploy ke server produksi.
 *
 *   php tests/jalankan_semua.php [URL_APLIKASI] [--server-bawaan]
 *
 * URL default http://localhost/parkit-app (untuk uji API ujung-ke-ujung). Uji API & backup membuat data
 * sementara lalu menghapusnya; jalankan di lokal/staging, bukan di server produksi.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
date_default_timezone_set('Asia/Jakarta');

$uji = ['tarif_test.php', 'ocr_test.php', 'migrasi_test.php', 'backup_test.php', 'api_test.php'];
$teruskan = array_slice($argv, 1);
$php = escapeshellarg(PHP_BINARY);
$gagal = [];
$mulai = microtime(true);

printf("PHP %s — %s\n\n", PHP_VERSION, date('Y-m-d H:i:s'));
foreach ($uji as $f) {
    $arg = $f === 'api_test.php' ? ' ' . implode(' ', array_map('escapeshellarg', $teruskan)) : '';
    $t = microtime(true);
    exec("$php " . escapeshellarg(__DIR__ . '/' . $f) . $arg . ' 2>&1', $out, $kode);
    $ringkas = array_values(array_filter($out, function ($l) { return strncmp($l, 'GAGAL', 5) === 0; }));
    printf("%-18s %s  (%.1f dtk)\n", $f, $kode === 0 ? 'LULUS' : 'GAGAL', microtime(true) - $t);
    foreach ($ringkas as $l) echo "    $l\n";
    if ($kode !== 0) {
        $gagal[] = $f;
        if (!$ringkas) echo '    ' . implode("\n    ", array_slice($out, -5)) . "\n";
    }
    $out = [];
}
printf("\n%s dalam %.1f detik\n", $gagal ? count($gagal) . ' berkas uji GAGAL: ' . implode(', ', $gagal) : 'SEMUA UJI LULUS', microtime(true) - $mulai);
echo "Uji tampilan mesin tarif versi JS: buka tests/tarif_parity.html di Chrome (lihat README).\n";
exit($gagal ? 1 : 0);
