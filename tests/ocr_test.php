<?php
/**
 * Uji pemilihan hasil OCR (tanpa memanggil API). Jalankan: php tests/ocr_test.php
 */
declare(strict_types=1);
function config($k, $d = null) { return $d; }
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/ocr.php';

$kasus = [
    // Hasil nyata dari foto plat W 2878 NEI (huruf timbul): tidak ada kandidat yang valid
    ['nama' => 'Plat modifikasi, semua kandidat salah format', 'hasil' => ['plate' => '2378dea', 'score' => 0.841, 'candidates' => [
        ['plate' => '2378dea', 'score' => 0.841], ['plate' => '2378bea', 'score' => 0.81]]],
     'plat' => '2378DEA', 'yakin' => false],
    ['nama' => 'Hasil teratas salah, kandidat ke-2 valid', 'hasil' => ['plate' => '1234abc', 'score' => 0.93, 'candidates' => [
        ['plate' => '1234abc', 'score' => 0.93], ['plate' => 'b1234abc', 'score' => 0.91]]],
     'plat' => 'B 1234 ABC', 'yakin' => true],
    ['nama' => 'Format valid tapi skor rendah', 'hasil' => ['plate' => 'b1234abc', 'score' => 0.62, 'candidates' => []],
     'plat' => 'B 1234 ABC', 'yakin' => false],
    ['nama' => 'Format valid, skor tinggi', 'hasil' => ['plate' => 'ad1234xy', 'score' => 0.97, 'candidates' => []],
     'plat' => 'AD 1234 XY', 'yakin' => true],
];

$gagal = 0;
foreach ($kasus as $k) {
    $r = ocr_pilih_hasil($k['hasil'], 0.9);
    $ok = $r['plat'] === $k['plat'] && $r['yakin'] === $k['yakin'];
    if (!$ok) $gagal++;
    printf("%s  %-46s %-11s yakin=%-5s %s\n", $ok ? 'OK   ' : 'GAGAL', $k['nama'], $r['plat'], var_export($r['yakin'], true), $r['alasan']);
}
echo $gagal ? "\n$gagal kasus gagal\n" : "\nSemua kasus lulus\n";
exit($gagal ? 1 : 0);
