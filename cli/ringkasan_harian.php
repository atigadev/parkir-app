<?php
/**
 * Ringkasan harian untuk pemilik/manajer: pendapatan kemarin + daftar "Perlu perhatian".
 * Dikirim ke alamat di Pengaturan -> Email ringkasan harian (fungsi mail() PHP; di cPanel sudah tersedia).
 *
 *   php cli/ringkasan_harian.php            kirim email ringkasan hari kemarin
 *   php cli/ringkasan_harian.php --cetak    tampilkan di layar saja (uji)
 *
 * Jadwalkan via cron setiap pagi, mis. 06:00.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require __DIR__ . '/../inc/bootstrap.php';

$tgl = date('Y-m-d', strtotime('-1 day'));
$k = q_one("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN metode_bayar='cash' THEN tk.total_biaya END),0) cash,
    COALESCE(SUM(CASE WHEN metode_bayar='qris' THEN tk.total_biaya END),0) qris, COALESCE(SUM(tk.tanpa_tiket),0) tt
    FROM transaksi_keluar tk JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id
    WHERE km.status <> 'void' AND tk.waktu_keluar >= ? AND tk.waktu_keluar < ? + INTERVAL 1 DAY", [$tgl, $tgl]);
$masuk = (int)q_val("SELECT COUNT(*) FROM kendaraan_masuk WHERE status <> 'void' AND waktu_masuk >= ? AND waktu_masuk < ? + INTERVAL 1 DAY", [$tgl, $tgl]);
$void = (int)q_val("SELECT COUNT(*) FROM kendaraan_masuk WHERE status = 'void' AND void_at >= ? AND void_at < ? + INTERVAL 1 DAY", [$tgl, $tgl]);
$sel = q_one("SELECT COALESCE(SUM(selisih),0) total, COUNT(CASE WHEN selisih <> 0 THEN 1 END) n FROM shift WHERE pos = 'keluar' AND waktu_tutup >= ? AND waktu_tutup < ? + INTERVAL 1 DAY", [$tgl, $tgl]);
$parkir = (int)q_val("SELECT COUNT(*) FROM kendaraan_masuk WHERE status = 'parkir'");
$perhatian = daftar_perhatian();

$lokasi = setting('nama_lokasi', 'ParkIt');
$baris = [
    "Ringkasan $lokasi — " . date('d/m/Y', strtotime($tgl)),
    str_repeat('=', 48),
    'Pendapatan      : ' . rupiah($k['cash'] + $k['qris']) . '  (cash ' . rupiah($k['cash']) . ', QRIS ' . rupiah($k['qris']) . ')',
    "Kendaraan masuk : $masuk",
    'Kendaraan keluar: ' . (int)$k['n'] . ((int)$k['tt'] ? ' (' . (int)$k['tt'] . ' tanpa tiket)' : ''),
    "Tiket void      : $void",
    'Selisih setoran : ' . rupiah($sel['total']) . ' (' . (int)$sel['n'] . ' shift berselisih)',
    "Masih parkir    : $parkir kendaraan",
    '',
    $perhatian ? 'PERLU PERHATIAN (' . count($perhatian) . ')' : 'Tidak ada yang perlu ditindaklanjuti.',
];
foreach ($perhatian as $p) {
    $baris[] = ($p['tingkat'] === 'merah' ? '[!] ' : '[-] ') . $p['judul'];
    $baris[] = '    ' . $p['detail'];
}
$teks = implode("\n", $baris) . "\n";

if (in_array('--cetak', $argv, true)) { echo $teks; exit(0); }

$tujuan = array_filter(array_map('trim', explode(',', (string)setting('email_laporan'))), function ($e) { return (bool)filter_var($e, FILTER_VALIDATE_EMAIL); });
if (!$tujuan) { fwrite(STDERR, "Email ringkasan belum diatur (Admin -> Pengaturan -> Email ringkasan harian).\n"); exit(1); }
$merah = count(array_filter($perhatian, function ($p) { return $p['tingkat'] === 'merah'; }));
$subjek = '[' . $lokasi . '] Ringkasan ' . date('d/m', strtotime($tgl)) . ' — ' . rupiah($k['cash'] + $k['qris']) . ($merah ? " — $merah perlu perhatian" : '');
$host = preg_replace('/[^a-z0-9.-]/i', '', (string)(gethostname() ?: 'localhost'));
$ok = mail(implode(',', $tujuan), '=?UTF-8?B?' . base64_encode($subjek) . '?=', $teks,
    "From: ParkIt <parkit@$host>\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit");
log_aktivitas(null, $ok ? 'ringkasan_harian' : 'ringkasan_harian_gagal', null, null, ['ke' => $tujuan, 'tanggal' => $tgl]);
echo $ok ? 'Ringkasan dikirim ke ' . implode(', ', $tujuan) . "\n" : "Gagal mengirim email (periksa konfigurasi mail server).\n";
exit($ok ? 0 : 1);
