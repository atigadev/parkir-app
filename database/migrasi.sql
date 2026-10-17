-- ParkIt — migrasi skema database (IDEMPOTEN: aman dijalankan berulang kali)
--
-- Untuk database yang dibuat dari schema.sql versi lama. Hanya menambahkan yang belum ada;
-- data & pengaturan yang sudah diatur tidak diubah. Kompatibel MySQL 5.7/8 & MariaDB.
--
-- Biasanya TIDAK perlu dijalankan manual: aplikasi menerapkan migrasi yang sama otomatis pada
-- permintaan pertama setelah update (lihat inc/migrasi.php). Jalankan file ini bila akun database
-- aplikasi tidak punya hak ALTER, atau setelah memulihkan backup lama:
--   phpMyAdmin -> pilih database -> tab SQL / Import -> file ini
--   atau: mysql -u USER -p NAMA_DB < database/migrasi.sql

-- Prosedur bantu: tambah kolom hanya bila belum ada --------------------------------------------
DROP PROCEDURE IF EXISTS parkit_tambah_kolom;
DELIMITER //
CREATE PROCEDURE parkit_tambah_kolom(IN p_tabel VARCHAR(64), IN p_kolom VARCHAR(64), IN p_definisi VARCHAR(255))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabel AND COLUMN_NAME = p_kolom) THEN
    SET @parkit_sql = CONCAT('ALTER TABLE `', p_tabel, '` ADD COLUMN `', p_kolom, '` ', p_definisi);
    PREPARE parkit_stmt FROM @parkit_sql;
    EXECUTE parkit_stmt;
    DEALLOCATE PREPARE parkit_stmt;
  END IF;
END //
DELIMITER ;

-- v1: kolom & pengaturan setelah rilis awal ----------------------------------------------------
CALL parkit_tambah_kolom('shift', 'pos_nama', 'VARCHAR(50) NOT NULL DEFAULT '''' AFTER pos');
INSERT IGNORE INTO pengaturan (kunci, nilai) VALUES
 ('ocr_ambang',     '0.9'),
 ('footer_struk',   'Terima kasih atas kunjungan Anda'),
 ('telepon_lokasi', '');

-- v2: versi aplikasi petugas -------------------------------------------------------------------
CALL parkit_tambah_kolom('petugas', 'app_versi',    'VARCHAR(20) NULL AFTER last_login');
CALL parkit_tambah_kolom('petugas', 'app_versi_at', 'DATETIME NULL AFTER app_versi');

-- v3: audit transaksi offline & koreksi waktu tiket offline ---------------------------------------
CALL parkit_tambah_kolom('transaksi_keluar', 'total_offline', 'INT NULL AFTER total_biaya');
CALL parkit_tambah_kolom('kendaraan_masuk', 'catatan_sistem', 'VARCHAR(255) NULL AFTER offline');
SET @parkit_sql = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'log_aktivitas' AND INDEX_NAME = 'idx_aksi_ref_waktu') = 0,
  'ALTER TABLE log_aktivitas ADD INDEX idx_aksi_ref_waktu (aksi, referensi_id, waktu)', 'SELECT 1');
PREPARE parkit_stmt FROM @parkit_sql; EXECUTE parkit_stmt; DEALLOCATE PREPARE parkit_stmt;

-- v4: sesi aplikasi petugas per perangkat ---------------------------------------------------
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
) ENGINE=InnoDB;

-- v5: kondisi HP petugas (antrean offline & selisih jam) ---------------------------------------
CALL parkit_tambah_kolom('petugas', 'antrean_offline',   'INT NULL AFTER app_versi_at');
CALL parkit_tambah_kolom('petugas', 'antrean_sejak',     'DATETIME NULL AFTER antrean_offline');
CALL parkit_tambah_kolom('petugas', 'jam_selisih_detik', 'INT NULL AFTER antrean_sejak');

-- Catat versi skema (tidak pernah menurunkan versi yang lebih tinggi) --------------------------
INSERT INTO pengaturan (kunci, nilai) VALUES ('skema_versi', '5')
  ON DUPLICATE KEY UPDATE nilai = GREATEST(CAST(nilai AS UNSIGNED), 5);

DROP PROCEDURE IF EXISTS parkit_tambah_kolom;

SELECT CONCAT('Migrasi selesai. Versi skema: ', nilai) AS hasil FROM pengaturan WHERE kunci = 'skema_versi';
