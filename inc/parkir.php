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
        $waktu = date('Y-m-d H:i:s', strtotime((string)$d['waktu_masuk']));
        $urai = qr_urai($kode);
        if (!is_uuid($id) || !$urai || $urai['id'] !== $id) {
            throw new ParkirException('Data tiket offline tidak valid.', 'qr_invalid');
        }
        if (q_val('SELECT 1 FROM kendaraan_masuk WHERE id = ?', [$id])) {
            return masuk_by_id($id); // sudah tersinkron sebelumnya (idempoten)
        }
    } else {
        $id = uuid4();
        $waktu = now();
        $kode = qr_buat($id, $jenis, $waktu);
    }

    q('INSERT INTO kendaraan_masuk (id, kode_qr, plat_nomor, plat_hasil_ocr, plat_dikoreksi, jenis_kendaraan, foto_masuk_url,
        waktu_masuk, pos_masuk, petugas_id, shift_id, status, offline) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $id, $kode, $plat, $ocr, ($ocr !== null && $ocr !== $plat) ? 1 : 0, $jenis, $d['foto'] ?? null,
        $waktu, mb_substr((string)($d['pos_masuk'] ?? 'Pos Masuk'), 0, 50), $petugasId, $shiftId, 'parkir', $offline ? 1 : 0,
    ]);

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
    // Waktu dari klien hanya diterima bila wajar (tidak di masa depan; online maks 15 menit lalu).
    if ($ts > time() + 60) $ts = time();
    if (!$offline && $ts < time() - 900) $ts = time();
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
        $total = $r['total'];
        if ($offline && isset($d['total_offline']) && (int)$d['total_offline'] !== $total) {
            // Uang yang benar-benar diterima petugas saat offline yang dicatat; selisih disimpan untuk audit.
            $r['total_server'] = $total;
            $total = (int)$d['total_offline'];
            $r['total'] = $total;
            $catatan = trim($catatan . ' [offline: hitungan server ' . $r['total_server'] . ']');
        }

        q('INSERT INTO transaksi_keluar (kendaraan_masuk_id, waktu_keluar, durasi_menit, rincian_biaya, total_biaya, metode_bayar,
            foto_keluar_url, petugas_id, shift_id, tanpa_tiket, denda, catatan, offline) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $km['id'], $waktuKeluar, $r['durasi_menit'], json_encode($r, JSON_UNESCAPED_UNICODE), $total, $metode,
            $d['foto'] ?? null, $petugasId, $shiftId, $tanpaTiket ? 1 : 0, $r['denda'], $catatan !== '' ? mb_substr($catatan, 0, 255) : null, $offline ? 1 : 0,
        ]);
        $tkId = (int)$pdo->lastInsertId();
        q("UPDATE kendaraan_masuk SET status = 'keluar' WHERE id = ?", [$km['id']]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    if ($tanpaTiket) {
        log_aktivitas($petugasId, 'keluar_tanpa_tiket', $km['id'], null, ['plat' => $km['plat_nomor'], 'denda' => $r['denda'], 'catatan' => $catatan]);
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
    return [
        'kendaraan_masuk'  => $masuk,
        'kendaraan_keluar' => (int)$k['jumlah'],
        'total_cash'       => (int)$k['cash'],
        'total_qris'       => (int)$k['qris'],
    ];
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
