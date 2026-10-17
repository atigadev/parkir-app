<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

$hari = date('Y-m-d');
$parkir = q_all("SELECT jenis_kendaraan, COUNT(*) n FROM kendaraan_masuk WHERE status = 'parkir' GROUP BY jenis_kendaraan");
$parkirJml = ['motor' => 0, 'mobil' => 0];
foreach ($parkir as $r) $parkirJml[$r['jenis_kendaraan']] = (int)$r['n'];
$menginap = (int)q_val("SELECT COUNT(*) FROM kendaraan_masuk WHERE status = 'parkir' AND waktu_masuk < ?", [$hari . ' 00:00:00']);

$masukHari = (int)q_val("SELECT COUNT(*) FROM kendaraan_masuk WHERE status <> 'void' AND waktu_masuk >= ? AND waktu_masuk < ? + INTERVAL 1 DAY", [$hari, $hari]);
$k = q_one("SELECT COUNT(*) n, COALESCE(SUM(tk.total_biaya),0) total,
    COALESCE(SUM(CASE WHEN metode_bayar='cash' THEN tk.total_biaya END),0) cash,
    COALESCE(SUM(CASE WHEN metode_bayar='qris' THEN tk.total_biaya END),0) qris
    FROM transaksi_keluar tk JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id
    WHERE km.status <> 'void' AND tk.waktu_keluar >= ? AND tk.waktu_keluar < ? + INTERVAL 1 DAY", [$hari, $hari]);

$perPos = q_all("SELECT COALESCE(NULLIF(s.pos_nama,''), 'Tanpa shift') pos,
    COUNT(*) n, SUM(CASE WHEN metode_bayar='cash' THEN tk.total_biaya ELSE 0 END) cash,
    SUM(CASE WHEN metode_bayar='qris' THEN tk.total_biaya ELSE 0 END) qris
    FROM transaksi_keluar tk JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id LEFT JOIN shift s ON s.id = tk.shift_id
    WHERE km.status <> 'void' AND tk.waktu_keluar >= ? AND tk.waktu_keluar < ? + INTERVAL 1 DAY
    GROUP BY pos ORDER BY pos", [$hari, $hari]);

$perJam = array_fill(0, 24, 0);
foreach (q_all("SELECT HOUR(waktu_masuk) j, COUNT(*) n FROM kendaraan_masuk WHERE status <> 'void' AND waktu_masuk >= ? AND waktu_masuk < ? + INTERVAL 1 DAY GROUP BY j", [$hari, $hari]) as $r) {
    $perJam[(int)$r['j']] = (int)$r['n'];
}
$maxJam = max(1, max($perJam));

$kolVersi = kolom_ada('petugas', 'app_versi') ? ', p.app_versi, p.app_versi_at' : '';
$shiftBuka = q_all("SELECT s.*, p.nama$kolVersi FROM shift s JOIN petugas p ON p.id = s.petugas_id WHERE s.waktu_tutup IS NULL ORDER BY s.waktu_buka");
// Indikasi kebocoran pendapatan: transaksi offline yang nominal HP-nya beda dari tagihan server, dan tiket offline berjam janggal
$offlineSelisih = kolom_tersedia('transaksi_keluar', 'total_offline') ? q_one("SELECT COUNT(*) n, COALESCE(SUM(tk.total_biaya - tk.total_offline), 0) kurang FROM transaksi_keluar tk JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id WHERE km.status <> 'void' AND tk.total_offline IS NOT NULL AND tk.waktu_keluar >= CURDATE() - INTERVAL 6 DAY") : ['n' => 0, 'kurang' => 0];
$waktuDikoreksi = kolom_tersedia('kendaraan_masuk', 'catatan_sistem') ? (int)q_val("SELECT COUNT(*) FROM kendaraan_masuk WHERE catatan_sistem IS NOT NULL AND waktu_masuk >= CURDATE() - INTERVAL 6 DAY") : 0;
$voidMinta = (int)q_val("SELECT COUNT(*) FROM kendaraan_masuk WHERE void_diminta_at IS NOT NULL AND status <> 'void' AND void_oleh IS NULL");
$qrisBelum = (int)q_val("SELECT COUNT(*) FROM transaksi_keluar tk JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id WHERE km.status <> 'void' AND tk.metode_bayar = 'qris' AND tk.qris_terverifikasi = 0 AND tk.total_biaya > 0");
$selisihHari = q_one("SELECT COUNT(*) n, COALESCE(SUM(selisih),0) total FROM shift WHERE pos = 'keluar' AND waktu_tutup >= ? AND selisih <> 0", [$hari]);

layout_atas('Dashboard', 'index.php');
?>
<?php if ($offlineSelisih['n'] || $waktuDikoreksi): ?>
<div class="alert err">
  <?php if ($offlineSelisih['n']): ?><a href="transaksi.php?status=offline_selisih&dari=<?= date('Y-m-d', strtotime('-6 days')) ?>"><?= (int)$offlineSelisih['n'] ?> transaksi offline</a> (7 hari) nominal di HP berbeda dari tagihan server<?= $offlineSelisih['kurang'] > 0 ? ' — total kurang ' . rupiah($offlineSelisih['kurang']) : '' ?>. <?php endif; ?>
  <?php if ($waktuDikoreksi): ?><a href="transaksi.php?status=waktu_dikoreksi&dari=<?= date('Y-m-d', strtotime('-6 days')) ?>"><?= $waktuDikoreksi ?> tiket offline</a> berjam janggal dan telah dikoreksi sistem.<?php endif; ?>
</div>
<?php endif; ?>
<?php if ($voidMinta || $qrisBelum): ?>
<div class="alert warn">
  <?php if ($voidMinta): ?><a href="transaksi.php?void=minta"><?= $voidMinta ?> permintaan void</a> menunggu persetujuan. <?php endif; ?>
  <?php if ($qrisBelum): ?><a href="qris.php"><?= $qrisBelum ?> transaksi QRIS</a> belum direkonsiliasi.<?php endif; ?>
</div>
<?php endif; ?>

<div class="grid g4" style="margin-bottom:18px">
  <div class="stat accent"><div class="label">Sedang parkir</div><div class="value"><?= $parkirJml['motor'] + $parkirJml['mobil'] ?></div><div class="sub"><?= $parkirJml['motor'] ?> motor · <?= $parkirJml['mobil'] ?> mobil<?= $menginap ? " · $menginap menginap" : '' ?></div></div>
  <div class="stat"><div class="label">Pendapatan hari ini</div><div class="value"><?= rupiah($k['total']) ?></div><div class="sub"><?= (int)$k['n'] ?> kendaraan keluar</div></div>
  <div class="stat"><div class="label">Masuk hari ini</div><div class="value"><?= $masukHari ?></div><div class="sub"><?= tgl($hari, 'l, d M Y') ?></div></div>
  <div class="stat"><div class="label">Selisih setoran hari ini</div><div class="value" style="color:<?= $selisihHari['total'] < 0 ? 'var(--danger)' : 'inherit' ?>"><?= rupiah($selisihHari['total']) ?></div><div class="sub"><?= (int)$selisihHari['n'] ?> shift dengan selisih</div></div>
</div>

<div class="grid g2">
  <div class="card">
    <div class="card-h"><h2>Pendapatan per pos & metode</h2><a href="laporan.php" class="btn sm ghost">Laporan lengkap</a></div>
    <div class="card-b">
      <?php $tot = max(1, (int)$k['total']); ?>
      <div class="split"><i style="width:<?= 100 * $k['cash'] / $tot ?>%;background:var(--ok)"></i><i style="width:<?= 100 * $k['qris'] / $tot ?>%;background:var(--accent)"></i></div>
      <div class="legend"><span><i class="dot" style="background:var(--ok)"></i>Cash <?= rupiah($k['cash']) ?></span><span><i class="dot" style="background:var(--accent)"></i>QRIS <?= rupiah($k['qris']) ?></span></div>
    </div>
    <div class="card-b flush table-wrap">
      <table>
        <thead><tr><th>Pos keluar</th><th class="num">Kendaraan</th><th class="num">Cash</th><th class="num">QRIS</th></tr></thead>
        <tbody>
        <?php foreach ($perPos as $r): ?>
          <tr><td><?= e($r['pos']) ?></td><td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= rupiah($r['cash']) ?></td><td class="num"><?= rupiah($r['qris']) ?></td></tr>
        <?php endforeach; if (!$perPos): ?><tr><td colspan="4" class="empty">Belum ada kendaraan keluar hari ini.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>Kendaraan masuk per jam</h2><span class="muted">Hari ini</span></div>
    <div class="card-b">
      <div class="bars">
        <?php foreach ($perJam as $j => $n): ?>
          <div class="bar" title="<?= sprintf('%02d:00', $j) ?> — <?= $n ?> kendaraan"><i style="height:<?= round(100 * $n / $maxJam) ?>%"></i><small><?= $j % 3 === 0 ? sprintf('%02d', $j) : '' ?></small></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>Shift berjalan</h2><a href="shift.php" class="btn sm ghost">Semua shift</a></div>
  <div class="card-b flush table-wrap">
    <table>
      <thead><tr><th>Petugas</th><th>Pos</th><th>Dibuka</th><th class="num">Masuk</th><th class="num">Keluar</th><th class="num">Cash sistem</th><th class="num">QRIS</th><th>Versi aplikasi</th></tr></thead>
      <tbody>
      <?php foreach ($shiftBuka as $s): $rs = ringkasan_shift((int)$s['id']); ?>
        <tr>
          <td><?= e($s['nama']) ?></td>
          <td><?= badge($s['pos'], $s['pos'] === 'masuk' ? 'info' : 'ok') ?> <?= e($s['pos_nama']) ?></td>
          <td><?= tgl($s['waktu_buka']) ?></td>
          <td class="num"><?= $rs['kendaraan_masuk'] ?></td>
          <td class="num"><?= $rs['kendaraan_keluar'] ?></td>
          <td class="num"><?= $s['pos'] === 'masuk' ? '<span class="muted">–</span>' : rupiah($rs['total_cash']) ?></td>
          <td class="num"><?= $s['pos'] === 'masuk' ? '<span class="muted">–</span>' : rupiah($rs['total_qris']) ?></td>
          <td><?= badge_versi($s['app_versi'] ?? null, $s['app_versi_at'] ?? null) ?></td>
        </tr>
      <?php endforeach; if (!$shiftBuka): ?><tr><td colspan="8" class="empty">Tidak ada shift yang sedang berjalan.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_bawah();
