<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

$mode = ($_GET['mode'] ?? 'harian') === 'bulanan' ? 'bulanan' : 'harian';
if ($mode === 'harian') {
    $bulan = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['bulan'] ?? '')) ? $_GET['bulan'] : date('Y-m');
    $dari = $bulan . '-01';
    $sampaiEx = date('Y-m-d', strtotime($dari . ' +1 month'));
    $fmtKey = '%Y-%m-%d';
} else {
    $tahun = preg_match('/^\d{4}$/', (string)($_GET['tahun'] ?? '')) ? $_GET['tahun'] : date('Y');
    $dari = $tahun . '-01-01';
    $sampaiEx = ($tahun + 1) . '-01-01';
    $fmtKey = '%Y-%m';
}

$data = [];
$tambah = function (string $k) use (&$data) {
    if (!isset($data[$k])) $data[$k] = ['masuk' => 0, 'keluar' => 0, 'cash' => 0, 'qris' => 0, 'durasi' => 0, 'tanpa_tiket' => 0, 'denda' => 0, 'gratis' => 0];
};
foreach (q_all("SELECT DATE_FORMAT(waktu_masuk, '$fmtKey') k, COUNT(*) n FROM kendaraan_masuk WHERE status <> 'void' AND waktu_masuk >= ? AND waktu_masuk < ? GROUP BY k", [$dari, $sampaiEx]) as $r) {
    $tambah($r['k']); $data[$r['k']]['masuk'] = (int)$r['n'];
}
foreach (q_all("SELECT DATE_FORMAT(tk.waktu_keluar, '$fmtKey') k, COUNT(*) n,
        SUM(CASE WHEN metode_bayar='cash' THEN total_biaya ELSE 0 END) cash, SUM(CASE WHEN metode_bayar='qris' THEN total_biaya ELSE 0 END) qris,
        AVG(durasi_menit) durasi, SUM(tanpa_tiket) tt, SUM(denda) denda, SUM(total_biaya = 0) gratis
        FROM transaksi_keluar tk JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id
        WHERE km.status <> 'void' AND tk.waktu_keluar >= ? AND tk.waktu_keluar < ? GROUP BY k", [$dari, $sampaiEx]) as $r) {
    $tambah($r['k']);
    $data[$r['k']] = ['masuk' => $data[$r['k']]['masuk'], 'keluar' => (int)$r['n'], 'cash' => (int)$r['cash'], 'qris' => (int)$r['qris'],
        'durasi' => (int)round((float)$r['durasi']), 'tanpa_tiket' => (int)$r['tt'], 'denda' => (int)$r['denda'], 'gratis' => (int)$r['gratis']];
}
ksort($data);

$shifts = q_all("SELECT s.*, p.nama FROM shift s JOIN petugas p ON p.id = s.petugas_id
    WHERE s.waktu_tutup >= ? AND s.waktu_tutup < ? AND s.pos = 'keluar' ORDER BY s.waktu_tutup", [$dari, $sampaiEx]);

$sum = ['masuk' => 0, 'keluar' => 0, 'cash' => 0, 'qris' => 0, 'tanpa_tiket' => 0, 'denda' => 0, 'gratis' => 0];
$durTot = 0;
foreach ($data as $d) { foreach ($sum as $k => $_) $sum[$k] += $d[$k]; $durTot += $d['durasi'] * $d['keluar']; }
$avgDur = $sum['keluar'] ? (int)round($durTot / $sum['keluar']) : 0;

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan-parkir-' . ($mode === 'harian' ? $bulan : $tahun) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Periode', 'Masuk', 'Keluar', 'Cash', 'QRIS', 'Total', 'Rata-rata durasi (menit)', 'Tanpa tiket', 'Denda', 'Gratis'], ';');
    foreach ($data as $k => $d) fputcsv($out, [$k, $d['masuk'], $d['keluar'], $d['cash'], $d['qris'], $d['cash'] + $d['qris'], $d['durasi'], $d['tanpa_tiket'], $d['denda'], $d['gratis']], ';');
    fputcsv($out, ['TOTAL', $sum['masuk'], $sum['keluar'], $sum['cash'], $sum['qris'], $sum['cash'] + $sum['qris'], $avgDur, $sum['tanpa_tiket'], $sum['denda'], $sum['gratis']], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Shift ditutup', 'Petugas', 'Pos', 'Cash sistem', 'Setoran', 'Selisih', 'Setoran susulan', 'Status tindak lanjut', 'Catatan', 'Catatan tindak lanjut'], ';');
    foreach ($shifts as $s) fputcsv($out, array_map('csv_aman', [$s['waktu_tutup'], $s['nama'], $s['pos_nama'], $s['total_cash_sistem'], $s['total_cash_setor'], $s['selisih'],
        $s['setor_susulan'] ?? 0, (int)$s['selisih'] === 0 ? 'pas' : ($s['selisih_status'] ?? 'belum ditindaklanjuti'), $s['catatan'], $s['selisih_catatan'] ?? '']), ';');
    exit;
}

$maxPend = 1;
foreach ($data as $d) $maxPend = max($maxPend, $d['cash'] + $d['qris']);

layout_atas('Laporan', 'laporan.php');
?>
<div class="card">
  <div class="card-h">
    <form class="filters" method="get">
      <label class="f">Jenis laporan<select name="mode" data-auto-submit><option value="harian" <?= $mode === 'harian' ? 'selected' : '' ?>>Harian (per bulan)</option><option value="bulanan" <?= $mode === 'bulanan' ? 'selected' : '' ?>>Bulanan (per tahun)</option></select></label>
      <?php if ($mode === 'harian'): ?>
        <label class="f">Bulan<input type="month" name="bulan" value="<?= e($bulan) ?>"></label>
      <?php else: ?>
        <label class="f">Tahun<input type="number" name="tahun" min="2020" max="2100" value="<?= e($tahun) ?>"></label>
      <?php endif; ?>
      <button class="btn">Tampilkan</button>
    </form>
    <a class="btn ghost" href="<?= e(self_url(['export' => 'csv'])) ?>">Unduh CSV</a>
  </div>
  <div class="card-b">
    <div class="grid g4">
      <div class="stat accent"><div class="label">Total pendapatan</div><div class="value"><?= rupiah($sum['cash'] + $sum['qris']) ?></div><div class="sub">Cash <?= rupiah($sum['cash']) ?> · QRIS <?= rupiah($sum['qris']) ?></div></div>
      <div class="stat"><div class="label">Kendaraan masuk / keluar</div><div class="value"><?= number_format($sum['masuk'], 0, ',', '.') ?> / <?= number_format($sum['keluar'], 0, ',', '.') ?></div></div>
      <div class="stat"><div class="label">Rata-rata durasi</div><div class="value"><?= e(durasi_teks($avgDur)) ?></div></div>
      <div class="stat"><div class="label">Tanpa tiket</div><div class="value"><?= $sum['tanpa_tiket'] ?></div><div class="sub">Denda <?= rupiah($sum['denda']) ?> · <?= $sum['gratis'] ?> transaksi gratis</div></div>
    </div>
    <?php if ($data): ?>
    <div class="bars" style="height:160px;margin-top:18px">
      <?php foreach ($data as $k => $d): $t = $d['cash'] + $d['qris']; ?>
        <div class="bar" title="<?= e($k) ?>: <?= rupiah($t) ?>"><i style="height:<?= round(100 * $t / $maxPend) ?>%"></i><small><?= e($mode === 'harian' ? substr($k, 8) : substr($k, 5)) ?></small></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-b flush table-wrap">
    <table>
      <thead><tr><th><?= $mode === 'harian' ? 'Tanggal' : 'Bulan' ?></th><th class="num">Masuk</th><th class="num">Keluar</th><th class="num">Cash</th><th class="num">QRIS</th><th class="num">Total</th><th class="num">Rata durasi</th><th class="num">Tanpa tiket</th></tr></thead>
      <tbody>
      <?php foreach ($data as $k => $d): ?>
        <tr><td><?= e($mode === 'harian' ? tgl($k, 'D, d M') : tgl($k . '-01', 'F Y')) ?></td><td class="num"><?= $d['masuk'] ?></td><td class="num"><?= $d['keluar'] ?></td><td class="num"><?= rupiah($d['cash']) ?></td><td class="num"><?= rupiah($d['qris']) ?></td><td class="num"><b><?= rupiah($d['cash'] + $d['qris']) ?></b></td><td class="num"><?= e(durasi_teks($d['durasi'])) ?></td><td class="num"><?= $d['tanpa_tiket'] ?></td></tr>
      <?php endforeach; if (!$data): ?><tr><td colspan="8" class="empty">Belum ada data pada periode ini.</td></tr><?php endif; ?>
      </tbody>
      <?php if ($data): ?><tfoot><tr><td>Total</td><td class="num"><?= $sum['masuk'] ?></td><td class="num"><?= $sum['keluar'] ?></td><td class="num"><?= rupiah($sum['cash']) ?></td><td class="num"><?= rupiah($sum['qris']) ?></td><td class="num"><?= rupiah($sum['cash'] + $sum['qris']) ?></td><td class="num"><?= e(durasi_teks($avgDur)) ?></td><td class="num"><?= $sum['tanpa_tiket'] ?></td></tr></tfoot><?php endif; ?>
    </table>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>Selisih setoran per shift (pos keluar)</h2></div>
  <div class="card-b flush table-wrap">
    <table>
      <thead><tr><th>Ditutup</th><th>Petugas</th><th>Pos</th><th class="num">Cash sistem</th><th class="num">Setoran</th><th class="num">Selisih</th><th>Catatan</th></tr></thead>
      <tbody>
      <?php foreach ($shifts as $s): ?>
        <tr><td><?= tgl($s['waktu_tutup']) ?></td><td><?= e($s['nama']) ?></td><td><?= e($s['pos_nama']) ?></td><td class="num"><?= rupiah($s['total_cash_sistem']) ?></td><td class="num"><?= rupiah($s['total_cash_setor']) ?></td>
          <td class="num"><?= isset($s['selisih_status']) || array_key_exists('selisih_status', $s) ? badge_selisih($s) : ((int)$s['selisih'] === 0 ? badge('pas', 'ok') : badge(rupiah($s['selisih']), (int)$s['selisih'] < 0 ? 'danger' : 'warn')) ?></td><td><?= e($s['catatan']) ?></td></tr>
      <?php endforeach; if (!$shifts): ?><tr><td colspan="7" class="empty">Belum ada shift ditutup pada periode ini.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_bawah();
