<?php
/**
 * Migrasi skema database — idempoten.
 *
 * Setiap langkah aman dijalankan berulang kali: kolom/indeks/pengaturan hanya ditambahkan bila belum ada,
 * error "sudah ada" dianggap berhasil, dan data yang sudah ada tidak pernah ditimpa. Karena itu migrasi bisa:
 *  - berjalan otomatis pada permintaan web pertama setelah `git pull` (tanpa SQL manual di cPanel),
 *  - dijalankan manual: `php cli/migrasi.php`, atau impor database/migrasi.sql di phpMyAdmin,
 *  - diterapkan ke database hasil restore backup lama.
 *
 * Menambah migrasi baru: tambahkan langkah dengan nomor berikutnya di langkah_migrasi(), naikkan SKEMA_VERSI,
 * dan tambahkan SQL yang setara (juga idempoten) di database/migrasi.sql serta struktur akhirnya di schema.sql.
 */
declare(strict_types=1);

const SKEMA_VERSI = 5;

function kolom_ada(string $tabel, string $kolom): bool
{
    return (bool)q_val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$tabel, $kolom]);
}

function tabel_ada(string $tabel): bool
{
    return (bool)q_val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$tabel]);
}

/** Struktur tabel sesi per perangkat (sama dengan database/schema.sql & migrasi.sql). */
const SQL_PETUGAS_TOKEN = <<<'SQL'
CREATE TABLE IF NOT EXISTS petugas_token (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  petugas_id        INT UNSIGNED NOT NULL,
  token_hash        CHAR(64) NOT NULL,
  perangkat         VARCHAR(120) NULL,
  ip                VARCHAR(45) NULL,
  dibuat            DATETIME NOT NULL,
  kedaluwarsa       DATETIME NOT NULL,
  terakhir_dipakai  DATETIME NULL,
  UNIQUE KEY uk_token (token_hash),
  KEY idx_petugas (petugas_id),
  CONSTRAINT fk_token_petugas FOREIGN KEY (petugas_id) REFERENCES petugas(id) ON DELETE CASCADE
) ENGINE=InnoDB
SQL;

function indeks_ada(string $tabel, string $indeks): bool
{
    return (bool)q_val('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?', [$tabel, $indeks]);
}

/** Tambah kolom bila belum ada. Mengembalikan true bila ada perubahan. */
function tambah_kolom(string $tabel, string $kolom, string $definisi): bool
{
    if (kolom_ada($tabel, $kolom)) return false;
    try {
        db()->exec("ALTER TABLE `$tabel` ADD COLUMN `$kolom` $definisi");
        return true;
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1060) return false; // Duplicate column: sudah ditambahkan proses lain
        throw $e;
    }
}

/** Tambah indeks bila belum ada. */
function tambah_indeks(string $tabel, string $indeks, string $definisi): bool
{
    if (indeks_ada($tabel, $indeks)) return false;
    try {
        db()->exec("ALTER TABLE `$tabel` ADD $definisi");
        return true;
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1061) return false; // Duplicate key name
        throw $e;
    }
}

/** Isi pengaturan bawaan yang belum ada (nilai yang sudah diatur admin tidak diubah). */
function isi_pengaturan(array $nilai): int
{
    $n = 0;
    foreach ($nilai as $kunci => $isi) {
        $n += q('INSERT IGNORE INTO pengaturan (kunci, nilai) VALUES (?, ?)', [$kunci, $isi])->rowCount();
    }
    return $n;
}

/**
 * Langkah migrasi per versi skema. Setiap langkah mengembalikan daftar perubahan yang benar-benar dilakukan
 * (kosong = struktur sudah sesuai).
 */
function langkah_migrasi(): array
{
    return [
        // v1: kolom & pengaturan yang ditambahkan setelah rilis awal
        1 => function (): array {
            $ubah = [];
            if (tambah_kolom('shift', 'pos_nama', "VARCHAR(50) NOT NULL DEFAULT '' AFTER pos")) $ubah[] = 'shift.pos_nama';
            $n = isi_pengaturan([
                'ocr_ambang'     => '0.9',
                'footer_struk'   => 'Terima kasih atas kunjungan Anda',
                'telepon_lokasi' => '',
            ]);
            if ($n) $ubah[] = "$n pengaturan bawaan";
            return $ubah;
        },
        // v2: versi aplikasi petugas terakhir yang dipakai tiap akun
        2 => function (): array {
            $ubah = [];
            if (tambah_kolom('petugas', 'app_versi', 'VARCHAR(20) NULL AFTER last_login')) $ubah[] = 'petugas.app_versi';
            if (tambah_kolom('petugas', 'app_versi_at', 'DATETIME NULL AFTER app_versi')) $ubah[] = 'petugas.app_versi_at';
            return $ubah;
        },
        // v3: audit transaksi offline (nominal dari HP vs hitungan server) & koreksi waktu tiket offline
        3 => function (): array {
            $ubah = [];
            if (tambah_kolom('transaksi_keluar', 'total_offline', 'INT NULL AFTER total_biaya')) $ubah[] = 'transaksi_keluar.total_offline';
            if (tambah_kolom('kendaraan_masuk', 'catatan_sistem', 'VARCHAR(255) NULL AFTER offline')) $ubah[] = 'kendaraan_masuk.catatan_sistem';
            if (tambah_indeks('log_aktivitas', 'idx_aksi_ref_waktu', 'INDEX idx_aksi_ref_waktu (aksi, referensi_id, waktu)')) $ubah[] = 'indeks log login';
            return $ubah;
        },
        // v4: sesi aplikasi petugas per perangkat (login di HP kedua tidak lagi memutus HP pertama)
        4 => function (): array {
            if (tabel_ada('petugas_token')) return [];
            db()->exec(SQL_PETUGAS_TOKEN);
            return ['tabel petugas_token'];
        },
        // v5: kondisi HP petugas yang dilaporkan aplikasi (antrean offline & selisih jam)
        5 => function (): array {
            $ubah = [];
            if (tambah_kolom('petugas', 'antrean_offline', 'INT NULL AFTER app_versi_at')) $ubah[] = 'petugas.antrean_offline';
            if (tambah_kolom('petugas', 'antrean_sejak', 'DATETIME NULL AFTER antrean_offline')) $ubah[] = 'petugas.antrean_sejak';
            if (tambah_kolom('petugas', 'jam_selisih_detik', 'INT NULL AFTER antrean_sejak')) $ubah[] = 'petugas.jam_selisih_detik';
            return $ubah;
        },
    ];
}

function skema_versi_db(): int
{
    return (int)q_val("SELECT nilai FROM pengaturan WHERE kunci = 'skema_versi'");
}

/**
 * Jalankan migrasi.
 * $paksa = true: jalankan & periksa SEMUA langkah walau skema_versi sudah terbaru (mis. setelah restore backup).
 * Mengembalikan ['dari' => int, 'ke' => int, 'perubahan' => [versi => [..]]].
 */
function jalankan_migrasi(bool $paksa = false): array
{
    $dari = skema_versi_db();
    $hasil = ['dari' => $dari, 'ke' => $dari, 'perubahan' => []];
    if (!$paksa && $dari >= SKEMA_VERSI) return $hasil;

    // Kunci agar dua permintaan bersamaan tidak memigrasi berbarengan.
    $kunci = (int)q_val("SELECT GET_LOCK('parkit_migrasi', 10)");
    if ($kunci !== 1) throw new RuntimeException('Migrasi sedang berjalan di proses lain, coba lagi.');
    try {
        $dari = skema_versi_db(); // baca ulang setelah mendapat kunci
        foreach (langkah_migrasi() as $versi => $langkah) {
            if (!$paksa && $versi <= $dari) continue;
            $ubah = $langkah();
            if ($ubah) $hasil['perubahan'][$versi] = $ubah;
            if ($versi > $dari) {
                q("INSERT INTO pengaturan (kunci, nilai) VALUES ('skema_versi', ?) ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)", [(string)$versi]);
                $dari = $versi;
            }
        }
        $hasil['ke'] = max($dari, skema_versi_db());
    } finally {
        q("SELECT RELEASE_LOCK('parkit_migrasi')");
    }
    if ($hasil['perubahan']) {
        try { log_aktivitas(null, 'migrasi_skema', null, ['versi' => $hasil['dari']], ['versi' => $hasil['ke'], 'perubahan' => $hasil['perubahan']]); } catch (Throwable $e) {}
    }
    return $hasil;
}

/** Dipanggil dari bootstrap: hanya 1 query ringan bila skema sudah terbaru. */
function migrasi_otomatis(): void
{
    try {
        jalankan_migrasi(false);
    } catch (Throwable $e) {
        // Mis. akun database tanpa hak ALTER: aplikasi tetap jalan; jalankan database/migrasi.sql manual.
        error_log('[parkit] migrasi gagal: ' . $e->getMessage());
    }
}

/** Versi aplikasi petugas terbaru di server (dibaca dari pwa/versi.js). */
function versi_pwa_terbaru(): string
{
    static $v = null;
    if ($v === null) {
        $isi = @file_get_contents(APP_ROOT . '/pwa/versi.js');
        $v = ($isi && preg_match("/PARKIT_VERSI\s*=\s*'([^']+)'/", $isi, $m)) ? $m[1] : '';
    }
    return $v;
}

function nomor_versi(?string $v): int
{
    return (int)preg_replace('/\D/', '', (string)$v);
}
