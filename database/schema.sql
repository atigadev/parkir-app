-- ParkIt — skema database (MySQL / MariaDB)
-- Buat database lebih dulu, lalu impor file ini ke database tersebut:
--   Lokal  : mysql -u root -e "CREATE DATABASE parkit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--            mysql -u root parkit < database/schema.sql
--   cPanel : buat database di "MySQL Databases", lalu phpMyAdmin → pilih database → Import

CREATE TABLE IF NOT EXISTS petugas (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nama             VARCHAR(100) NOT NULL,
  username         VARCHAR(50)  NOT NULL UNIQUE,
  password_hash    VARCHAR(255) NOT NULL,
  role             ENUM('admin','petugas') NOT NULL DEFAULT 'petugas',
  aktif            TINYINT(1) NOT NULL DEFAULT 1,
  api_token_hash   CHAR(64) NULL,
  token_expires    DATETIME NULL,
  last_login       DATETIME NULL,
  app_versi        VARCHAR(20) NULL,
  app_versi_at     DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_token (api_token_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shift (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  petugas_id         INT UNSIGNED NOT NULL,
  pos                ENUM('masuk','keluar') NOT NULL,
  pos_nama           VARCHAR(50) NOT NULL DEFAULT '',
  waktu_buka         DATETIME NOT NULL,
  waktu_tutup        DATETIME NULL,
  total_cash_sistem  INT NULL,
  total_cash_setor   INT NULL,
  selisih            INT NULL,
  catatan            VARCHAR(255) NULL,
  KEY idx_petugas_open (petugas_id, waktu_tutup),
  CONSTRAINT fk_shift_petugas FOREIGN KEY (petugas_id) REFERENCES petugas(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tarif (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  jenis_kendaraan     ENUM('motor','mobil') NOT NULL,
  durasi_awal_menit   INT NOT NULL DEFAULT 60,
  biaya_awal          INT NOT NULL DEFAULT 0,
  biaya_per_jam       INT NOT NULL DEFAULT 0,
  maks_per_hari       INT NOT NULL DEFAULT 0,      -- 0 = tanpa batas
  biaya_menginap      INT NOT NULL DEFAULT 0,
  jam_batas_menginap  TIME NOT NULL DEFAULT '00:00:00',
  toleransi_menit     INT NOT NULL DEFAULT 0,
  berlaku_mulai       DATETIME NOT NULL,
  aktif               TINYINT(1) NOT NULL DEFAULT 1,
  dibuat_oleh         INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_jenis_berlaku (jenis_kendaraan, aktif, berlaku_mulai)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kendaraan_masuk (
  id                  CHAR(36) PRIMARY KEY,
  kode_qr             VARCHAR(80) NOT NULL,
  plat_nomor          VARCHAR(15) NOT NULL,
  plat_hasil_ocr      VARCHAR(15) NULL,
  plat_dikoreksi      TINYINT(1) NOT NULL DEFAULT 0,
  jenis_kendaraan     ENUM('motor','mobil') NOT NULL,
  foto_masuk_url      VARCHAR(255) NULL,
  waktu_masuk         DATETIME NOT NULL,
  pos_masuk           VARCHAR(50) NOT NULL DEFAULT 'Pos Masuk',
  petugas_id          INT UNSIGNED NOT NULL,
  shift_id            INT UNSIGNED NULL,
  status              ENUM('parkir','keluar','void') NOT NULL DEFAULT 'parkir',
  offline             TINYINT(1) NOT NULL DEFAULT 0,
  void_alasan         VARCHAR(255) NULL,
  void_diminta_oleh   INT UNSIGNED NULL,
  void_diminta_at     DATETIME NULL,
  void_oleh           INT UNSIGNED NULL,
  void_at             DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_kode_qr (kode_qr),
  KEY idx_plat_status (plat_nomor, status),
  KEY idx_waktu_masuk (waktu_masuk),
  KEY idx_shift (shift_id),
  CONSTRAINT fk_km_petugas FOREIGN KEY (petugas_id) REFERENCES petugas(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transaksi_keluar (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kendaraan_masuk_id  CHAR(36) NOT NULL,
  waktu_keluar        DATETIME NOT NULL,
  durasi_menit        INT NOT NULL,
  rincian_biaya       TEXT NOT NULL,               -- JSON
  total_biaya         INT NOT NULL,
  metode_bayar        ENUM('cash','qris') NOT NULL,
  foto_keluar_url     VARCHAR(255) NULL,
  petugas_id          INT UNSIGNED NOT NULL,
  shift_id            INT UNSIGNED NULL,
  tanpa_tiket         TINYINT(1) NOT NULL DEFAULT 0,
  denda               INT NOT NULL DEFAULT 0,
  catatan             VARCHAR(255) NULL,
  offline             TINYINT(1) NOT NULL DEFAULT 0,
  qris_terverifikasi  TINYINT(1) NOT NULL DEFAULT 0,
  qris_verif_oleh     INT UNSIGNED NULL,
  qris_verif_at       DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_masuk (kendaraan_masuk_id),
  KEY idx_shift (shift_id),
  KEY idx_waktu_keluar (waktu_keluar),
  CONSTRAINT fk_tk_masuk FOREIGN KEY (kendaraan_masuk_id) REFERENCES kendaraan_masuk(id),
  CONSTRAINT fk_tk_petugas FOREIGN KEY (petugas_id) REFERENCES petugas(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS plat_khusus (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plat_nomor      VARCHAR(15) NOT NULL,
  tipe            ENUM('member','dinas','gratis') NOT NULL,
  keterangan      VARCHAR(100) NULL,
  berlaku_sampai  DATE NULL,
  aktif           TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_plat (plat_nomor, aktif)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS log_aktivitas (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  petugas_id     INT UNSIGNED NULL,
  aksi           VARCHAR(40) NOT NULL,
  referensi_id   VARCHAR(40) NULL,
  data_sebelum   TEXT NULL,
  data_sesudah   TEXT NULL,
  waktu          DATETIME NOT NULL,
  ip             VARCHAR(45) NULL,
  KEY idx_waktu (waktu),
  KEY idx_aksi (aksi)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pengaturan (
  kunci  VARCHAR(50) PRIMARY KEY,
  nilai  TEXT NULL
) ENGINE=InnoDB;

-- Data awal --------------------------------------------------------------
-- Admin awal: admin / admin123 (WAJIB diganti setelah login pertama)
INSERT IGNORE INTO petugas (id, nama, username, password_hash, role) VALUES
 (1, 'Administrator', 'admin', '$2y$10$4nZKFZfwHm3YbU0A1Dz5U.C8esFVk77LMrd2I7sDMtcQoIwSRhC46', 'admin');

INSERT IGNORE INTO tarif (id, jenis_kendaraan, durasi_awal_menit, biaya_awal, biaya_per_jam, maks_per_hari, biaya_menginap, jam_batas_menginap, toleransi_menit, berlaku_mulai) VALUES
 (1, 'motor', 120, 2000, 1000, 10000,  5000, '00:00:00', 10, '2020-01-01 00:00:00'),
 (2, 'mobil',  60, 5000, 3000, 30000, 15000, '00:00:00', 10, '2020-01-01 00:00:00');

INSERT IGNORE INTO pengaturan (kunci, nilai) VALUES
 ('nama_lokasi',     'Parkir ParkIt'),
 ('alamat_lokasi',   ''),
 ('denda_tanpa_tiket_motor', '20000'),
 ('denda_tanpa_tiket_mobil', '50000'),
 ('qris_gambar',     ''),
 ('qris_nama_merchant', ''),
 ('ocr_url',         'https://api.platerecognizer.com/v1/plate-reader/'),
 ('ocr_token',       ''),
 ('ocr_timeout',     '3'),
 ('ocr_ambang',      '0.9'),
 ('retensi_foto_hari', '60'),
 ('footer_resi',     'Simpan tiket ini dengan baik\nJangan tinggalkan tiket & barang berharga di kendaraan\nKehilangan/kerusakan barang bukan tanggung jawab pengelola'),
 ('footer_struk',    'Terima kasih atas kunjungan Anda'),
 ('telepon_lokasi',  ''),
 ('skema_versi',     '2');
