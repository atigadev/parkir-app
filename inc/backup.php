<?php
/**
 * Backup database ke file .sql.gz (PHP murni). Dipakai cli/backup.php & tests/backup_test.php.
 */
declare(strict_types=1);

/**
 * Tulis dump SQL terkompresi secara atomik (.tmp lalu rename), hapus backup lama di luar jumlah simpan,
 * dan catat ringkasan di pengaturan 'backup_terakhir' (ditampilkan di dashboard admin).
 */
function backup_database(string $dir, int $simpan): array
{
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException("Folder backup tidak bisa dibuat: $dir");
    if (!is_writable($dir)) throw new RuntimeException("Folder backup tidak bisa ditulis: $dir");
    $nama = 'parkit-' . date('Ymd-His') . '.sql.gz';
    $file = $dir . DIRECTORY_SEPARATOR . $nama;
    $tmp = $file . '.tmp';
    $gz = gzopen($tmp, 'wb6');
    if (!$gz) throw new RuntimeException("Tidak bisa menulis $tmp");

    $pdo = db();
    $db = (string)q_val('SELECT DATABASE()');
    gzwrite($gz, "-- Backup ParkIt database `$db` " . date('Y-m-d H:i:s') . "\n-- Skema versi " . skema_versi_db() . "\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");
    $tabel = [];
    foreach (q_all("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $r) $tabel[] = array_values($r)[0];
    $totalBaris = 0;
    foreach ($tabel as $t) {
        $buat = q_one("SHOW CREATE TABLE `$t`");
        gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n" . array_values($buat)[1] . ";\n\n");
        $st = $pdo->query("SELECT * FROM `$t`");
        $kolom = null; $batch = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($kolom === null) $kolom = '(`' . implode('`,`', array_keys($row)) . '`)';
            $batch[] = '(' . implode(',', array_map(function ($v) use ($pdo) { return $v === null ? 'NULL' : $pdo->quote((string)$v); }, $row)) . ')';
            $totalBaris++;
            if (count($batch) >= 200) { gzwrite($gz, "INSERT INTO `$t` $kolom VALUES\n" . implode(",\n", $batch) . ";\n"); $batch = []; }
        }
        if ($batch) gzwrite($gz, "INSERT INTO `$t` $kolom VALUES\n" . implode(",\n", $batch) . ";\n");
        gzwrite($gz, "\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n-- selesai\n");
    gzclose($gz);
    if (!rename($tmp, $file)) throw new RuntimeException("Gagal menyimpan $file");

    // Retensi: sisakan $simpan backup terbaru
    $semua = glob($dir . DIRECTORY_SEPARATOR . 'parkit-*.sql.gz') ?: [];
    rsort($semua);
    foreach (array_slice($semua, $simpan) as $lama) @unlink($lama);

    $info = ['waktu' => now(), 'file' => $nama, 'ukuran' => filesize($file), 'tabel' => count($tabel), 'baris' => $totalBaris];
    set_setting('backup_terakhir', json_encode($info));
    log_aktivitas(null, 'backup', null, null, $info);
    return $info + ['path' => $file];
}
