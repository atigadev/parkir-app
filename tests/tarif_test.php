<?php
/**
 * Uji mesin tarif (tanpa database). Jalankan: php tests/tarif_test.php
 * Kasus yang sama dipakai tests/tarif_parity.html untuk memastikan versi JS identik.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Jakarta');
function config($k, $d = null) { return $d; }
require __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/tarif.php';

$json = file_get_contents(__DIR__ . '/tarif_cases.json');
$cases = json_decode($json, true);
// Salinan untuk tests/tarif_parity.html (uji versi JS di browser)
file_put_contents(__DIR__ . '/tarif_cases.js', 'var CASES = ' . trim($json) . ";\n");
$gagal = 0;
foreach ($cases['cases'] as $c) {
    $tarif = $cases['tarif'][$c['tarif']];
    $r = hitung_tarif($tarif, $c['masuk'], $c['keluar'], $c['khusus'] ?? null, $c['denda'] ?? 0);
    $ok = $r['total'] === $c['harapan'];
    if (!$ok) $gagal++;
    printf("%s  %-48s total=%-7d harapan=%d\n", $ok ? 'OK  ' : 'GAGAL', $c['nama'], $r['total'], $c['harapan']);
}
echo $gagal ? "\n$gagal kasus gagal\n" : "\nSemua kasus lulus\n";
exit($gagal ? 1 : 0);
