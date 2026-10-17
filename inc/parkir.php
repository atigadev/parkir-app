<?php
/**
 * Operasi domain parkir yang dipakai bersama oleh API online dan sinkronisasi offline.
 */
declare(strict_types=1);

class ParkirException extends RuntimeException
{
    public $kode;
    public $data;

    public function __construct(string $pesan, string $kode = 'error', array $data = [])
    {
        parent::__construct($pesan);
        $this->kode = $kode;
        $this->data = $data;
    }
}

/** Kolom yang ditambahkan migrasi tersedia? (di-cache per permintaan; aman bila migrasi gagal dijalankan) */
function kolom_tersedia(string $tabel, string $kolom): bool
{
    static $cache = [];
    $k = "$tabel.$kolom";
    if (!isset($cache[$k])) $cache[$k] = kolom_ada($tabel, $kolom);
    return $cache[$k];
}

function shift_aktif(int $petugasId): ?array
{
    return q_one('SELECT * FROM shift WHERE petugas_id = ? AND waktu_tutup IS NULL ORDER BY id DESC LIMIT 1', [$petugasId]);
}

function denda_tanpa_tiket(string $jenis): int
{
    return (int)setting('denda_tanpa_tiket_' . $jenis, '0');
}

function masuk_by_id(string $id): ?array
{
    return q_one('SELECT km.*, p.nama AS petugas_nama FROM kendaraan_masuk km JOIN petugas p ON p.id = km.petugas_id WHERE km.id = ?', [$id]);
}

/**
 * Catat kendaraan masuk.
 * $d: plat, plat_ocr, jenis, foto, pos_masuk, [id, kode_qr, waktu_masuk, offline]
 */
function catat_masuk(array $d, int $petugasId, ?int $shiftId): array
{
    $plat = normalisasi_plat($d['plat'] ?? '');
    if (strlen(str_replace(' ', '', $plat)) < 3) {
        throw new ParkirException('Plat nomor tidak valid.', 'plat_invalid');
    }
    $jenis = ($d['jenis'] ?? '') === 'mobil' ? 'mobil' : 'motor';
    $ocr = isset($d['plat_ocr']) && $d['plat_ocr'] !== '' ? normalisasi_plat($d['plat_ocr']) : null;
    $offline = !empty($d['offline']);

    if ($offline) {
        $id = (string)$d['id'];
        $kode = (string)$d['kode_qr'];
        $urai = qr_urai($kode);
        if (!is_uuid($id) || !$urai || $urai['id'] !== $id) {
            throw new ParkirException('Data tiket offline tidak valid.', 'qr_invalid');
        }
        $kode = $urai['kode'];
        if (q_val('SELECT 1 FROM kendaraan_masuk WHERE id = ?', [$id])) {
            return masuk_by_id($id); // sudah tersinkron sebelumnya (idempoten)
        }
        // Jam dari HP tidak dipercaya begitu saja: waktu masuk di masa depan / sebelum shift dibuka
        // membuat tarif keluar Rp0. Dikoreksi (bukan ditolak, agar tiket tidak hilang) & diberi tanda.
        $ts = strtotime((string)($d['waktu_masuk'] ?? '')) ?: time();
        $koreksi = [];
        if ($ts > time() + 120) { $koreksi[] = 'jam HP di masa depan (' . date('d/m H:i', $ts) . ')'; $ts = time(); }
        $buka = $shiftId ? strtotime((string)q_val('SELECT waktu_buka FROM shift WHERE id = ?', [$shiftId])) : 0;
        if ($buka && $ts < $buka - 300) { $koreksi[] = 'sebelum shift dibuka (' . date('d/m H:i', $ts) . ')'; $ts = $buka; }
        $waktu = date('Y-m-d H:i:s', $ts);
        $catatanSistem = $koreksi ? 'Waktu masuk offline dikoreksi: ' . implode(', ', $koreksi) : null;
    } else {
        $id = uuid4();
        $waktu = now();
        $kode = qr_buat($id, $jenis, $waktu);
        $catatanSistem = null;
    }

    q('INSERT INTO kendaraan_masuk (id, kode_qr, plat_nomor, plat_hasil_ocr, plat_dikoreksi, jenis_kendaraan, foto_masuk_url,
        waktu_masuk, pos_masuk, petugas_id, shift_id, status, offline) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $id, $kode, $plat, $ocr, ($ocr !== null && $ocr !== $plat) ? 1 : 0, $jenis, $d['foto'] ?? null,
        $waktu, mb_substr((string)($d['pos_masuk'] ?? 'Pos Masuk'), 0, 50), $petugasId, $shiftId, 'parkir', $offline ? 1 : 0,
    ]);

    if ($catatanSistem !== null) {
        if (kolom_tersedia('kendaraan_masuk', 'catatan_sistem')) q('UPDATE kendaraan_masuk SET catatan_sistem = ? WHERE id = ?', [$catatanSistem, $id]);
        log_aktivitas($petugasId, 'koreksi_waktu_offline', $id, ['waktu_hp' => (string)($d['waktu_masuk'] ?? '')], ['waktu_masuk' => $waktu, 'alasan' => $catatanSistem]);
    }
    if ($ocr !== null && $ocr !== $plat) {
        log_aktivitas($petugasId, 'koreksi_plat', $id, ['plat_ocr' => $ocr], ['plat' => $plat]);
    }
    return masuk_by_id($id);
}

/**
 * Cari kendaraan berstatus parkir dari nomor tiket yang diketik (mis. "6B95-A60D"),
 * untuk tiket yang QR-nya rusak/tidak terbaca.
 */
function masuk_by_no_tiket(string $no): ?array
{
    $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $no));
    if (strlen($hex) !== 8) return null;
    $rows = q_all("SELECT id FROM kendaraan_masuk WHERE id LIKE ? AND status <> 'void' ORDER BY waktu_masuk DESC LIMIT 2", [$hex . '%']);
    if (count($rows) !== 1) return null; // tidak ada, atau (sangat jarang) bentrok
    return masuk_by_id($rows[0]['id']);
}

/** Kendaraan lain dengan plat sama yang masih tercatat parkir. */
function cek_plat_masih_parkir(string $plat): ?array
{
    return q_one("SELECT id, waktu_masuk, pos_masuk FROM kendaraan_masuk WHERE plat_nomor = ? AND status = 'parkir' ORDER BY waktu_masuk DESC LIMIT 1", [normalisasi_plat($plat)]);
}

/** Hitung biaya untuk kendaraan_masuk pada waktu keluar tertentu (tanpa menyimpan). */
function hitung_untuk(array $km, string $waktuKeluar, bool $tanpaTiket): array
{
    $tarif = tarif_aktif($km['jenis_kendaraan'], $km['waktu_masuk']);
    if (!$tarif) {
        throw new ParkirException('Tarif untuk ' . $km['jenis_kendaraan'] . ' belum diatur.', 'tarif_kosong');
    }
    $khusus = plat_khusus_berlaku($km['plat_nomor'], $waktuKeluar);
    $denda = $tanpaTiket ? denda_tanpa_tiket($km['jenis_kendaraan']) : 0;
    return hitung_tarif($tarif, $km['waktu_masuk'], $waktuKeluar, $khusus, $denda);
}

/**
 * Simpan transaksi keluar.
 * $d: kendaraan_masuk_id, metode (cash|qris), waktu_keluar, tanpa_tiket, catatan, foto, [offline, total_offline]
 */
function catat_keluar(array $d, int $petugasId, ?int $shiftId): array
{
    $metode = ($d['metode'] ?? '') === 'qris' ? 'qris' : 'cash';
    $tanpaTiket = !empty($d['tanpa_tiket']);
    $offline = !empty($d['offline']);
    $catatan = trim((string)($d['catatan'] ?? ''));
    if ($tanpaTiket && $catatan === '') {
        throw new ParkirException('Catatan verifikasi (STNK/identitas) wajib diisi untuk keluar tanpa tiket.', 'catatan_wajib');
    }

    $waktuKeluar = $d['waktu_keluar'] ?? now();
    $ts = strtotime((string)$waktuKeluar);
    if (!$ts) $ts = time();
    // Waktu dari klien hanya diterima bila wajar. Online: API sudah memverifikasinya lewat token hitung
    // (token_hitung_waktu); di sini dijaga lagi agar tidak lebih lama dari masa berlaku token.
    if ($ts > time() + 60) $ts = time();
    if (!$offline && $ts < time() - TOKEN_HITUNG_DETIK) $ts = time();
    $waktuKeluar = date('Y-m-d H:i:s', $ts);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $km = q_one('SELECT * FROM kendaraan_masuk WHERE id = ? FOR UPDATE', [(string)($d['kendaraan_masuk_id'] ?? '')]);
        if (!$km) {
            throw new ParkirException('Data kendaraan masuk tidak ditemukan.', 'tidak_ditemukan');
        }
        if ($km['status'] === 'keluar') {
            $tk = q_one('SELECT * FROM transaksi_keluar WHERE kendaraan_masuk_id = ?', [$km['id']]);
            if ($offline && $tk) { // sinkronisasi ulang: idempoten
                $pdo->commit();
                return transaksi_keluar_lengkap((int)$tk['id']);
            }
            throw new ParkirException('Kendaraan ini sudah tercatat keluar pada ' . ($tk['waktu_keluar'] ?? '-') . '.', 'sudah_keluar');
        }
        if ($km['status'] === 'void') {
            throw new ParkirException('Tiket ini sudah dibatalkan (void).', 'void');
        }
        if ($waktuKeluar < $km['waktu_masuk']) $waktuKeluar = $km['waktu_masuk'];

        $r = hitung_untuk($km, $waktuKeluar, $tanpaTiket);
        // Tagihan SELALU menurut hitungan server. Nominal yang dicatat HP saat offline disimpan terpisah
        // (total_offline) bila berbeda: setoran shift diharapkan = hitungan server, sehingga nominal HP yang
        // lebih kecil (mis. dimanipulasi menjadi Rp0) langsung terlihat sebagai kekurangan setoran.
        $total = $r['total'];
        $totalOffline = null;
        if ($offline && isset($d['total_offline']) && is_numeric($d['total_offline']) && (int)$d['total_offline'] !== $total) {
            $totalOffline = max(0, (int)$d['total_offline']);
            $r['total_offline'] = $totalOffline;
            $catatan = trim($catatan . ' [offline: HP mencatat ' . rupiah($totalOffline) . ', tagihan server ' . rupiah($total) . ']');
        }

        q('INSERT INTO transaksi_keluar (kendaraan_masuk_id, waktu_keluar, durasi_menit, rincian_biaya, total_biaya, metode_bayar,
            foto_keluar_url, petugas_id, shift_id, tanpa_tiket, denda, catatan, offline) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $km['id'], $waktuKeluar, $r['durasi_menit'], json_encode($r, JSON_UNESCAPED_UNICODE), $total, $metode,
            $d['foto'] ?? null, $petugasId, $shiftId, $tanpaTiket ? 1 : 0, $r['denda'], $catatan !== '' ? mb_substr($catatan, 0, 255) : null, $offline ? 1 : 0,
        ]);
        $tkId = (int)$pdo->lastInsertId();
        if ($totalOffline !== null && kolom_tersedia('transaksi_keluar', 'total_offline')) {
            q('UPDATE transaksi_keluar SET total_offline = ? WHERE id = ?', [$totalOffline, $tkId]);
        }
        q("UPDATE kendaraan_masuk SET status = 'keluar' WHERE id = ?", [$km['id']]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    if ($tanpaTiket) {
        log_aktivitas($petugasId, 'keluar_tanpa_tiket', $km['id'], null, ['plat' => $km['plat_nomor'], 'denda' => $r['denda'], 'catatan' => $catatan]);
    }
    if ($totalOffline !== null) {
        log_aktivitas($petugasId, 'offline_berselisih', $km['id'], ['total_hp' => $totalOffline], ['tagihan_server' => $total, 'plat' => $km['plat_nomor']]);
    }
    return transaksi_keluar_lengkap($tkId);
}

function transaksi_keluar_lengkap(int $id): ?array
{
    $row = q_one('SELECT tk.*, km.plat_nomor, km.jenis_kendaraan, km.waktu_masuk, km.pos_masuk, km.foto_masuk_url, p.nama AS petugas_nama,
            s.pos_nama AS pos_keluar
        FROM transaksi_keluar tk JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id JOIN petugas p ON p.id = tk.petugas_id
        LEFT JOIN shift s ON s.id = tk.shift_id
        WHERE tk.id = ?', [$id]);
    if ($row) {
        $row['rincian'] = json_decode($row['rincian_biaya'], true);
        $row['rincian_baris'] = rincian_baris($row['rincian']);
        unset($row['rincian_biaya']);
    }
    return $row;
}

/** Total cash yang seharusnya dipegang petugas pada shift (tidak termasuk transaksi void). */
function total_cash_shift(int $shiftId): int
{
    return (int)q_val("SELECT COALESCE(SUM(tk.total_biaya),0) FROM transaksi_keluar tk
        JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id
        WHERE tk.shift_id = ? AND tk.metode_bayar = 'cash' AND km.status <> 'void'", [$shiftId]);
}

function ringkasan_shift(int $shiftId): array
{
    $masuk = (int)q_val("SELECT COUNT(*) FROM kendaraan_masuk WHERE shift_id = ? AND status <> 'void'", [$shiftId]);
    $k = q_one("SELECT COUNT(*) AS jumlah,
            COALESCE(SUM(CASE WHEN tk.metode_bayar='cash' THEN tk.total_biaya END),0) AS cash,
            COALESCE(SUM(CASE WHEN tk.metode_bayar='qris' THEN tk.total_biaya END),0) AS qris
        FROM transaksi_keluar tk JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id
        WHERE tk.shift_id = ? AND km.status <> 'void'", [$shiftId]);
    // Rekap tiket pos masuk (pos masuk tidak menerima uang; yang dipertanggungjawabkan adalah tiket)
    $t = q_one("SELECT
            COALESCE(SUM(status <> 'void' AND jenis_kendaraan = 'motor'), 0) AS motor,
            COALESCE(SUM(status <> 'void' AND jenis_kendaraan = 'mobil'), 0) AS mobil,
            COALESCE(SUM(status = 'void'), 0) AS void,
            COALESCE(SUM(status <> 'void' AND offline = 1), 0) AS offline,
            COALESCE(SUM(status = 'parkir'), 0) AS masih_parkir,
            COALESCE(SUM(status = 'parkir' AND void_diminta_at IS NOT NULL), 0) AS minta_void
        FROM kendaraan_masuk WHERE shift_id = ?", [$shiftId]);
    return [
        'kendaraan_masuk'  => $masuk,
        'kendaraan_keluar' => (int)$k['jumlah'],
        'total_cash'       => (int)$k['cash'],
        'total_qris'       => (int)$k['qris'],
        'tiket' => [
            'motor'        => (int)$t['motor'],
            'mobil'        => (int)$t['mobil'],
            'void'         => (int)$t['void'],
            'offline'      => (int)$t['offline'],
            'masih_parkir' => (int)$t['masih_parkir'],
            'minta_void'   => (int)$t['minta_void'],
        ],
    ];
}

/**
 * Tutup shift. Pos keluar: rekap uang (cash sistem vs setoran → selisih).
 * Pos masuk: tidak menerima uang, jadi tanpa setoran/selisih (tercatat NULL); yang direkap jumlah tiket.
 */
function tutup_shift(array $shift, ?int $setor, ?string $catatan): array
{
    if ($shift['pos'] === 'masuk') {
        $sistem = 0; $setor = null; $selisih = null;
    } else {
        if ($setor === null || $setor < 0) throw new ParkirException('Isi jumlah uang setoran.', 'setor');
        $sistem = total_cash_shift((int)$shift['id']);
        $selisih = $setor - $sistem;
    }
    q('UPDATE shift SET waktu_tutup = ?, total_cash_sistem = ?, total_cash_setor = ?, selisih = ?, catatan = ? WHERE id = ?', [
        now(), $sistem, $setor, $selisih, $catatan !== null && trim($catatan) !== '' ? mb_substr(trim($catatan), 0, 255) : null, $shift['id'],
    ]);
    return ['sistem' => $sistem, 'setor' => $setor, 'selisih' => $selisih];
}

/** Batalkan (void) tiket oleh admin. Tidak ada data yang dihapus. */
function void_tiket(string $id, int $adminId, string $alasan): void
{
    $alasan = trim($alasan);
    if ($alasan === '') throw new ParkirException('Alasan void wajib diisi.', 'alasan_wajib');
    $km = q_one('SELECT * FROM kendaraan_masuk WHERE id = ?', [$id]);
    if (!$km) throw new ParkirException('Tiket tidak ditemukan.', 'tidak_ditemukan');
    if ($km['status'] === 'void') throw new ParkirException('Tiket sudah void.', 'void');
    q("UPDATE kendaraan_masuk SET status = 'void', void_alasan = ?, void_oleh = ?, void_at = ? WHERE id = ?", [mb_substr($alasan, 0, 255), $adminId, now(), $id]);
    log_aktivitas($adminId, 'void', $id, ['status' => $km['status'], 'plat' => $km['plat_nomor']], ['status' => 'void', 'alasan' => $alasan]);
}
