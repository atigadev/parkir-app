<?php
/**
 * Uji idempotensi migrasi skema di database UJI terpisah (database aplikasi tidak disentuh).
 *   php tests/migrasi_test.php            (memakai database uji "parkit_ujimigrasi", dibuat & dihapus otomatis)
 *
 * Skenario:
 *  1. Database lama (kolom/pengaturan baru belum ada) -> migrasi menambahkan semuanya
 *  2. Migrasi dijalankan ulang 2x -> tidak ada perubahan, tidak ada error
 *  3. Restore backup lama (skema_versi tercatat terbaru tetapi kolom hilang) -> --periksa memperbaiki
 *  4. Nilai pengaturan yang diubah admin tidak ditimpa
 *  5. Dua proses menjalankan migrasi bersamaan -> keduanya sukses
 *  6. database/migrasi.sql dijalankan 2x lewat mysql CLI -> tanpa error, hasil sama
 *  7. database/schema.sql diimpor 2x -> tanpa error
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require __DIR__ . '/../inc/bootstrap.php';

$DB = 'parkit_ujimigrasi';
$c = config('db');
$mysql = getenv('MYSQL_BIN') ?: (is_file('C:/xampp/mysql/bin/mysql.exe') ? 'C:/xampp/mysql/bin/mysql.exe' : 'mysql');
$cred = '-h ' . escapeshellarg($c['host']) . ' -P ' . (int)$c['port'] . ' -u ' . escapeshellarg($c['user']) . ($c['pass'] !== '' ? ' -p' . escapeshellarg($c['pass']) : '');

// Arahkan koneksi aplikasi ke database uji
$pdoRoot = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']), $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['CONFIG']['db']['name'] = $DB;

$gagal = 0;
function cek(string $nama, bool $ok, string $info = ''): void
{
    global $gagal;
    if (!$ok) $gagal++;
    printf("%s %s%s\n", $ok ? 'LULUS' : 'GAGAL', $nama, $info !== '' ? "  ($info)" : '');
}
function mysql_file(string $file): array
{
    global $mysql, $cred, $DB;
    exec(escapeshellarg($mysql) . " $cred " . escapeshellarg($DB) . ' < ' . escapeshellarg($file) . ' 2>&1', $out, $kode);
    return [$kode, implode("\n", $out)];
}
/** Buat database uji dari schema.sql lalu turunkan ke kondisi "versi lama". */
function siapkan_db_lama(PDO $root): void
{
    global $DB;
    $root->exec("DROP DATABASE IF EXISTS `$DB`");
    $root->exec("CREATE DATABASE `$DB` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    [$k, $o] = mysql_file(APP_ROOT . '/database/schema.sql');
    if ($k !== 0) { echo "Gagal impor schema.sql: $o\n"; exit(1); }
    $pdo = db();
    $pdo->exec('ALTER TABLE shift DROP COLUMN pos_nama');
    $pdo->exec('ALTER TABLE petugas DROP COLUMN app_versi, DROP COLUMN app_versi_at');
    $pdo->exec('ALTER TABLE transaksi_keluar DROP COLUMN total_offline');
    $pdo->exec('ALTER TABLE kendaraan_masuk DROP COLUMN catatan_sistem');
    $pdo->exec('ALTER TABLE log_aktivitas DROP INDEX idx_aksi_ref_waktu');
    $pdo->exec('DROP TABLE petugas_token');
    $pdo->exec('ALTER TABLE petugas DROP COLUMN antrean_offline, DROP COLUMN antrean_sejak, DROP COLUMN jam_selisih_detik');
    $pdo->exec('ALTER TABLE shift DROP COLUMN setor_susulan, DROP COLUMN selisih_status, DROP COLUMN selisih_catatan, DROP COLUMN selisih_oleh, DROP COLUMN selisih_at');
    $pdo->exec('ALTER TABLE shift DROP INDEX idx_pos_terbuka, DROP COLUMN pos_id');
    $pdo->exec('DROP TABLE pos_petugas');
    $pdo->exec('DROP TABLE pos');
    $pdo->exec("DELETE FROM pengaturan WHERE kunci IN ('ocr_ambang','footer_struk','telepon_lokasi','skema_versi')");
}
function struktur_lengkap(): bool
{
    return kolom_ada('shift', 'pos_nama') && kolom_ada('petugas', 'app_versi') && kolom_ada('petugas', 'app_versi_at')
        && kolom_ada('transaksi_keluar', 'total_offline') && kolom_ada('kendaraan_masuk', 'catatan_sistem') && indeks_ada('log_aktivitas', 'idx_aksi_ref_waktu') && tabel_ada('petugas_token') && kolom_ada('petugas', 'jam_selisih_detik') && kolom_ada('petugas', 'antrean_sejak') && kolom_ada('shift', 'selisih_status') && kolom_ada('shift', 'setor_susulan') && tabel_ada('pos') && tabel_ada('pos_petugas') && kolom_ada('shift', 'pos_id')
        && q_val("SELECT COUNT(*) FROM pengaturan WHERE kunci IN ('ocr_ambang','footer_struk','telepon_lokasi')") == 3;
}

try {
    // 1
    siapkan_db_lama($pdoRoot);
    cek('1a. kondisi awal = versi lama', !struktur_lengkap() && skema_versi_db() === 0);
    $h = jalankan_migrasi();
    cek('1b. migrasi menambahkan kolom & pengaturan', struktur_lengkap() && $h['ke'] === SKEMA_VERSI, json_encode($h['perubahan'], JSON_UNESCAPED_UNICODE));

    // 2
    $h2 = jalankan_migrasi(); $h3 = jalankan_migrasi(true);
    cek('2. dijalankan ulang (biasa & --periksa) tanpa perubahan', !$h2['perubahan'] && !$h3['perubahan'] && struktur_lengkap());

    // 3
    db()->exec('ALTER TABLE petugas DROP COLUMN app_versi_at');
    $hb = jalankan_migrasi();
    cek('3a. restore backup: migrasi biasa tidak memeriksa ulang (cepat)', !$hb['perubahan'] && !kolom_ada('petugas', 'app_versi_at'));
    $hp = jalankan_migrasi(true);
    cek('3b. --periksa memperbaiki kolom yang hilang', kolom_ada('petugas', 'app_versi_at'), json_encode($hp['perubahan']));

    // 4
    q("UPDATE pengaturan SET nilai = 'Sampai jumpa lagi' WHERE kunci = 'footer_struk'");
    jalankan_migrasi(true);
    cek('4. pengaturan yang diubah admin tidak ditimpa', q_val("SELECT nilai FROM pengaturan WHERE kunci = 'footer_struk'") === 'Sampai jumpa lagi');

    // 5
    siapkan_db_lama($pdoRoot);
    $anak = tempnam(sys_get_temp_dir(), 'mig') . '.php';
    file_put_contents($anak, '<?php $_SERVER["DOCUMENT_ROOT"] = ' . var_export($_SERVER['DOCUMENT_ROOT'], true) . ";\n"
        . 'require ' . var_export(APP_ROOT . '/inc/bootstrap.php', true) . ";\n"
        . '$GLOBALS["CONFIG"]["db"]["name"] = ' . var_export($DB, true) . ";\n"
        . 'try { jalankan_migrasi(); echo "OK"; } catch (Throwable $e) { echo "ERR " . $e->getMessage(); }');
    $skrip = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($anak);
    $p1 = popen($skrip, 'r'); $p2 = popen($skrip, 'r');
    $o1 = stream_get_contents($p1); $o2 = stream_get_contents($p2); pclose($p1); pclose($p2);
    @unlink($anak);
    cek('5. dua proses bersamaan sama-sama sukses', trim($o1) === 'OK' && trim($o2) === 'OK' && struktur_lengkap(), trim($o1) . ' / ' . trim($o2));

    // 6
    siapkan_db_lama($pdoRoot);
    [$k1, $o1] = mysql_file(APP_ROOT . '/database/migrasi.sql');
    [$k2, $o2] = mysql_file(APP_ROOT . '/database/migrasi.sql');
    cek('6a. migrasi.sql dijalankan 2x tanpa error', $k1 === 0 && $k2 === 0, $k1 || $k2 ? trim($o1 . ' ' . $o2) : 'versi skema ' . skema_versi_db());
    cek("6b. hasil migrasi.sql = hasil migrasi PHP", struktur_lengkap() && skema_versi_db() === SKEMA_VERSI && !jalankan_migrasi(true)['perubahan']);
    q("UPDATE pengaturan SET nilai = '9' WHERE kunci = 'skema_versi'");
    mysql_file(APP_ROOT . '/database/migrasi.sql');
    cek('6c. migrasi.sql tidak menurunkan versi skema yang lebih tinggi', skema_versi_db() === 9);

    // 7
    $pdoRoot->exec("DROP DATABASE IF EXISTS `$DB`");
    $pdoRoot->exec("CREATE DATABASE `$DB` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    [$k1, $o1] = mysql_file(APP_ROOT . '/database/schema.sql');
    [$k2, $o2] = mysql_file(APP_ROOT . '/database/schema.sql');
    cek('7. schema.sql diimpor 2x tanpa error & skema langsung terbaru', $k1 === 0 && $k2 === 0 && struktur_lengkap() && skema_versi_db() === SKEMA_VERSI, $k1 || $k2 ? trim($o1 . ' ' . $o2) : '');
} finally {
    $pdoRoot->exec("DROP DATABASE IF EXISTS `$DB`");
}

echo $gagal ? "\n$gagal kasus gagal\n" : "\nSemua kasus lulus\n";
exit($gagal ? 1 : 0);
