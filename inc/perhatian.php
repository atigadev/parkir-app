<?php
/**
 * Daftar hal yang perlu ditindaklanjuti admin: indikasi kebocoran pendapatan, masalah perangkat petugas,
 * dan kesehatan sistem. Dipakai panel "Perlu perhatian" di dashboard & ringkasan harian (email).
 */
declare(strict_types=1);

/**
 * @return array<int, array{tingkat:string, judul:string, detail:string, tautan:?string}>
 *   tingkat: 'merah' (segera) | 'kuning' (periksa)
 */
function daftar_perhatian(): array
{
    $hasil = [];
    $tambah = function (string $tingkat, string $judul, string $detail, ?string $tautan = null) use (&$hasil) {
        $hasil[] = ['tingkat' => $tingkat, 'judul' => $judul, 'detail' => $detail, 'tautan' => $tautan];
    };
    $tujuhHari = date('Y-m-d', strtotime('-6 days'));
    $kemarin24 = date('Y-m-d H:i:s', time() - 86400);

    // --- Pendapatan
    if (kolom_tersedia('transaksi_keluar', 'total_offline')) {
        $r = q_one("SELECT COUNT(*) n, COALESCE(SUM(tk.total_biaya - tk.total_offline), 0) kurang FROM transaksi_keluar tk
            JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id
            WHERE km.status <> 'void' AND tk.total_offline IS NOT NULL AND tk.waktu_keluar >= ?", [$tujuhHari]);
        if ((int)$r['n']) $tambah('merah', $r['n'] . ' transaksi offline berselisih (7 hari)',
            'Nominal di HP berbeda dari tagihan server' . ((int)$r['kurang'] > 0 ? ', total kurang ' . rupiah($r['kurang']) : '') . '. Cocokkan dengan setoran petugas.',
            "transaksi.php?status=offline_selisih&dari=$tujuhHari");
    }
    $r = q_one("SELECT COUNT(*) n, COALESCE(SUM(selisih), 0) total FROM shift WHERE pos = 'keluar' AND waktu_tutup >= ? AND selisih < 0", [$tujuhHari]);
    if ((int)$r['n']) $tambah('merah', $r['n'] . ' shift kurang setor (7 hari)', 'Total kekurangan ' . rupiah(-(int)$r['total']) . '.', "shift.php?dari=$tujuhHari");
    if (kolom_tersedia('kendaraan_masuk', 'catatan_sistem')) {
        $n = (int)q_val('SELECT COUNT(*) FROM kendaraan_masuk WHERE catatan_sistem IS NOT NULL AND waktu_masuk >= ?', [$tujuhHari]);
        if ($n) $tambah('kuning', "$n tiket offline berjam janggal (7 hari)", 'Waktu masuk dari HP di masa depan atau sebelum shift dibuka, sudah dikoreksi sistem.', "transaksi.php?status=waktu_dikoreksi&dari=$tujuhHari");
    }
    $n = (int)q_val("SELECT COUNT(*) FROM kendaraan_masuk WHERE void_diminta_at IS NOT NULL AND status <> 'void' AND void_oleh IS NULL");
    if ($n) $tambah('kuning', "$n permintaan void menunggu", 'Setujui atau tolak di menu Transaksi.', 'transaksi.php?void=minta');
    $r = q_one("SELECT COUNT(*) n, COALESCE(SUM(tk.total_biaya), 0) total, MIN(DATE(tk.waktu_keluar)) tgl FROM transaksi_keluar tk
        JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id
        WHERE km.status <> 'void' AND tk.metode_bayar = 'qris' AND tk.qris_terverifikasi = 0 AND tk.total_biaya > 0 AND tk.waktu_keluar < CURDATE()");
    if ((int)$r['n']) $tambah('kuning', $r['n'] . ' transaksi QRIS hari sebelumnya belum direkonsiliasi', rupiah($r['total']) . ' belum dicocokkan dengan mutasi rekening.', 'qris.php?tgl=' . $r['tgl']);

    // --- Perangkat & petugas
    $n = (int)q_val("SELECT COUNT(*) FROM log_aktivitas WHERE aksi = 'sync_ditolak' AND waktu >= ?", [$kemarin24]);
    if ($n) $tambah('merah', "$n transaksi offline ditolak server (24 jam)", 'Biasanya QR palsu/rusak atau data tidak valid. Periksa di Log Aktivitas.', 'log.php?aksi=sync_ditolak');
    if (kolom_tersedia('petugas', 'antrean_offline')) {
        foreach (q_all('SELECT nama, antrean_offline, antrean_sejak FROM petugas WHERE antrean_offline > 0 AND antrean_sejak < ? AND app_versi_at >= ?', [date('Y-m-d H:i:s', time() - 900), $kemarin24]) as $p) {
            $tambah('merah', 'Antrean offline tertahan di HP ' . $p['nama'], $p['antrean_offline'] . ' transaksi belum terkirim sejak ' . date('d/m H:i', strtotime($p['antrean_sejak'])) . '. Data hanya ada di HP tersebut.', 'petugas.php');
        }
        foreach (q_all('SELECT nama, jam_selisih_detik FROM petugas WHERE ABS(jam_selisih_detik) > 120 AND app_versi_at >= ?', [$kemarin24]) as $p) {
            $m = (int)round(abs((int)$p['jam_selisih_detik']) / 60);
            $tambah('kuning', 'Jam HP ' . $p['nama'] . ' tidak sinkron', 'Selisih ±' . $m . ' menit dengan server: tiket & tarif offline bisa salah.', 'petugas.php');
        }
    }
    if (kolom_tersedia('petugas', 'app_versi') && ($terbaru = versi_pwa_terbaru())) {
        foreach (q_all('SELECT p.nama, p.app_versi FROM petugas p JOIN shift s ON s.petugas_id = p.id AND s.waktu_tutup IS NULL WHERE p.app_versi IS NOT NULL') as $p) {
            if (nomor_versi($p['app_versi']) < nomor_versi($terbaru)) $tambah('kuning', 'HP ' . $p['nama'] . ' masih aplikasi ' . $p['app_versi'], "Versi terbaru $terbaru. Minta petugas menekan Muat ulang.", 'petugas.php');
        }
    }
    foreach (q_all("SELECT referensi_id u, COUNT(*) n FROM log_aktivitas WHERE aksi = 'login_gagal' AND waktu >= ? GROUP BY referensi_id HAVING n >= ?", [$kemarin24, LOGIN_MAKS_GAGAL_AKUN]) as $r) {
        $tambah('kuning', $r['n'] . ' login gagal untuk "' . $r['u'] . '" (24 jam)', 'Kemungkinan lupa password atau upaya menebak password.', 'log.php?aksi=login_gagal');
    }
    foreach (q_all("SELECT p.nama, s.pos_nama, s.waktu_buka FROM shift s JOIN petugas p ON p.id = s.petugas_id WHERE s.waktu_tutup IS NULL AND s.waktu_buka < ?", [date('Y-m-d H:i:s', time() - 16 * 3600)]) as $s) {
        $tambah('kuning', 'Shift ' . $s['nama'] . ' belum ditutup sejak ' . date('d/m H:i', strtotime($s['waktu_buka'])), 'Lebih dari 16 jam: kemungkinan lupa tutup shift. Bisa ditutup paksa oleh admin.', 'shift.php');
    }

    // --- Sistem
    foreach (q_all("SELECT nama, username, password_hash FROM petugas WHERE role = 'admin' AND aktif = 1") as $a) {
        if (password_bawaan($a['password_hash'])) $tambah('merah', 'Akun admin "' . $a['username'] . '" masih memakai password bawaan', 'Segera ganti password.', 'petugas.php');
    }
    $b = json_decode((string)setting('backup_terakhir', ''), true);
    if (!$b || empty($b['waktu'])) {
        $tambah('merah', 'Belum ada backup database', 'Jadwalkan cli/backup.php harian (lihat README: Tugas terjadwal).', null);
    } elseif (strtotime($b['waktu']) < time() - 36 * 3600) {
        $tambah('merah', 'Backup terakhir ' . date('d/m/Y H:i', strtotime($b['waktu'])), 'Lebih dari 36 jam lalu. Periksa jadwal cron/Task Scheduler backup.', null);
    }
    if (skema_versi_db() < SKEMA_VERSI) {
        $tambah('merah', 'Migrasi database belum lengkap (v' . skema_versi_db() . ' dari v' . SKEMA_VERSI . ')', 'Jalankan php cli/migrasi.php atau impor database/migrasi.sql.', null);
    }

    usort($hasil, function ($a, $b) { return ($a['tingkat'] === 'merah' ? 0 : 1) - ($b['tingkat'] === 'merah' ? 0 : 1); });
    return $hasil;
}
