<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

if (is_post()) {
    cek_csrf();
    $ids = array_map('intval', (array)($_POST['ids'] ?? []));
    $verif = ($_POST['aksi'] ?? '') === 'verif' ? 1 : 0;
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        q("UPDATE transaksi_keluar SET qris_terverifikasi = ?, qris_verif_oleh = ?, qris_verif_at = ? WHERE metode_bayar = 'qris' AND id IN ($in)",
            array_merge([$verif, $verif ? admin_id() : null, $verif ? now() : null], $ids));
        log_aktivitas(admin_id(), $verif ? 'rekonsiliasi_qris' : 'batal_rekonsiliasi_qris', null, null, ['ids' => $ids]);
        flash(count($ids) . ' transaksi ' . ($verif ? 'ditandai cocok dengan mutasi.' : 'dikembalikan ke belum terverifikasi.'));
    }
    redirect(self_url());
}

$tgl = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['tgl'] ?? '')) ? $_GET['tgl'] : date('Y-m-d');
$rows = q_all("SELECT tk.*, km.plat_nomor, p.nama petugas, s.pos_nama
    FROM transaksi_keluar tk JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id JOIN petugas p ON p.id = tk.petugas_id LEFT JOIN shift s ON s.id = tk.shift_id
    WHERE tk.metode_bayar = 'qris' AND km.status <> 'void' AND tk.waktu_keluar >= ? AND tk.waktu_keluar < ? + INTERVAL 1 DAY ORDER BY tk.waktu_keluar", [$tgl, $tgl]);
$tot = 0; $totV = 0;
foreach ($rows as $r) { $tot += $r['total_biaya']; if ($r['qris_terverifikasi']) $totV += $r['total_biaya']; }
$belumLain = q_all("SELECT DATE(tk.waktu_keluar) d, COUNT(*) n FROM transaksi_keluar tk JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id
    WHERE tk.metode_bayar = 'qris' AND km.status <> 'void' AND tk.qris_terverifikasi = 0 AND tk.total_biaya > 0 AND DATE(tk.waktu_keluar) <> ? GROUP BY d ORDER BY d DESC LIMIT 14", [$tgl]);

layout_atas('Rekonsiliasi QRIS', 'qris.php');
?>
<div class="alert warn">QRIS statis tidak mengirim konfirmasi otomatis. Cocokkan setiap transaksi di bawah dengan mutasi rekening / aplikasi merchant, lalu centang yang sesuai. Transaksi yang tidak ada di mutasi perlu ditindaklanjuti dengan petugasnya.</div>
<div class="grid g3" style="margin-bottom:18px">
  <div class="stat accent"><div class="label">Total QRIS menurut sistem</div><div class="value"><?= rupiah($tot) ?></div><div class="sub"><?= count($rows) ?> transaksi · <?= tgl($tgl, 'd M Y') ?></div></div>
  <div class="stat"><div class="label">Sudah cocok dengan mutasi</div><div class="value" style="color:var(--ok)"><?= rupiah($totV) ?></div></div>
  <div class="stat"><div class="label">Belum diverifikasi</div><div class="value" style="color:<?= $tot - $totV ? 'var(--warn)' : 'inherit' ?>"><?= rupiah($tot - $totV) ?></div></div>
</div>
<?php if ($belumLain): ?>
<p class="muted">Hari lain yang belum selesai:
  <?php foreach ($belumLain as $b): ?><a class="badge warn" href="?tgl=<?= e($b['d']) ?>"><?= tgl($b['d'], 'd/m') ?> (<?= (int)$b['n'] ?>)</a> <?php endforeach; ?>
</p>
<?php endif; ?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="card-h">
    <div class="filters"><label class="f">Tanggal<input type="date" value="<?= e($tgl) ?>" onchange="location='?tgl='+this.value"></label></div>
    <div class="actions"><button class="btn ok" name="aksi" value="verif">Tandai cocok</button><button class="btn ghost" name="aksi" value="batal">Batalkan tanda</button></div>
  </div>
  <div class="card-b flush table-wrap">
    <table>
      <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.cb').forEach(c=>c.checked=this.checked)" aria-label="Pilih semua"></th><th>Waktu</th><th>Plat</th><th class="num">Nominal</th><th>Pos / Petugas</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><input type="checkbox" class="cb" name="ids[]" value="<?= (int)$r['id'] ?>"></td>
          <td class="mono"><?= tgl($r['waktu_keluar'], 'H:i:s') ?></td>
          <td><a href="transaksi.php?id=<?= e($r['kendaraan_masuk_id']) ?>"><span class="plat"><?= e($r['plat_nomor']) ?></span></a></td>
          <td class="num"><b><?= rupiah($r['total_biaya']) ?></b></td>
          <td><?= e($r['pos_nama']) ?> · <?= e($r['petugas']) ?></td>
          <td><?= $r['qris_terverifikasi'] ? badge('cocok', 'ok') : badge('belum', 'warn') ?></td>
        </tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">Tidak ada transaksi QRIS pada tanggal ini.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</form>
<?php layout_bawah();
