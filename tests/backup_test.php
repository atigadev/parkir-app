<?php
/**
 * Uji backup & PEMULIHAN: buat backup, pulihkan ke database uji terpisah, bandingkan isi setiap tabel.
 *   php tests/backup_test.php
 * Database aplikasi tidak diubah; database uji "parkit_ujibackup" dibuat & dihapus otomatis.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/backup.php';
$mulaiUji = now();

$gagal = 0;
function cek(string $nama, bool $ok, string $info = ''): void
{
    global $gagal;
    if (!$ok) $gagal++;
    printf("%s %s%s\n", $ok ? 'LULUS' : 'GAGAL', $nama, $info !== '' ? "  ($info)" : '');
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'parkit_backup_uji_' . bin2hex(random_bytes(4));
$c = config('db');
$UJI = 'parkit_ujibackup';
$root = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']), $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sebelumSetting = setting('backup_terakhir', '');

try {
    // Hitung isi database aplikasi
    $tabel = [];
    foreach (q_all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $r) $tabel[] = array_values($r)[0];
    $asli = [];
    foreach ($tabel as $t) $asli[$t] = [(int)q_val("SELECT COUNT(*) FROM `$t`"), (string)q_val("CHECKSUM TABLE `$t`") ?: ''];

    // 1. backup
    $h = backup_database($dir, 3);
    cek('1. backup dibuat & terkompresi', is_file($h['path']) && $h['ukuran'] > 0 && substr($h['file'], -7) === '.sql.gz', round($h['ukuran'] / 1024, 1) . ' KB, ' . $h['tabel'] . ' tabel');
    cek('2. tidak ada file .tmp tertinggal (penulisan atomik)', !glob($dir . '/*.tmp'));

    // 3. pulihkan ke database uji
    $root->exec("DROP DATABASE IF EXISTS `$UJI`");
    $root->exec("CREATE DATABASE `$UJI` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdoUji = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $UJI), $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $sql = gzdecode((string)file_get_contents($h['path']));
    $pdoUji->exec($sql);
    $cocok = true; $beda = [];
    foreach ($tabel as $t) {
        $n = (int)$pdoUji->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        if ($n !== $asli[$t][0]) { $cocok = false; $beda[] = "$t: $n vs {$asli[$t][0]}"; }
    }
    cek('3. dipulihkan ke database uji: jumlah baris setiap tabel sama', $cocok, $cocok ? count($tabel) . ' tabel cocok' : implode('; ', $beda));
    $isiSama = true;
    foreach (['petugas', 'tarif', 'pengaturan'] as $t) {
        // Bandingkan sebagai teks (kedua koneksi bisa mengembalikan tipe berbeda); abaikan catatan backup yang ditulis SETELAH dump.
        $teks = function (array $rows) { return json_encode(array_map(function ($r) { return array_map(function ($v) { return $v === null ? null : (string)$v; }, $r); }, $rows)); };
        $w = $t === 'pengaturan' ? " WHERE kunci <> 'backup_terakhir'" : '';
        $a = $teks(q_all("SELECT * FROM `$t`$w ORDER BY 1"));
        $b = $teks($pdoUji->query("SELECT * FROM `$t`$w ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC));
        if ($a !== $b) $isiSama = false;
    }
    cek('4. isi tabel petugas, tarif, pengaturan identik setelah dipulihkan', $isiSama);

    // 5. retensi
    for ($i = 0; $i < 4; $i++) { sleep(1); backup_database($dir, 3); }
    cek('5. retensi: hanya 3 backup terbaru yang disimpan', count(glob($dir . '/parkit-*.sql.gz')) === 3);
    cek('6. info backup terakhir tercatat untuk dashboard', !empty(json_decode((string)q_val("SELECT nilai FROM pengaturan WHERE kunci = 'backup_terakhir'"), true)['waktu']));
} finally {
    $root->exec("DROP DATABASE IF EXISTS `$UJI`");
    foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
    // kembalikan catatan backup terakhir & hapus log uji
    if ($sebelumSetting === '') q("DELETE FROM pengaturan WHERE kunci = 'backup_terakhir'"); else set_setting('backup_terakhir', $sebelumSetting);
    q("DELETE FROM log_aktivitas WHERE aksi = 'backup' AND waktu >= ?", [$mulaiUji]);
}

echo $gagal ? "\n$gagal kasus gagal\n" : "\nSemua kasus lulus\n";
exit($gagal ? 1 : 0);
