<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

$aksi = (string)($_GET['aksi'] ?? '');
$pid = (int)($_GET['petugas'] ?? 0);
$dari = (string)($_GET['dari'] ?? date('Y-m-d', strtotime('-6 days')));
$sampai = (string)($_GET['sampai'] ?? date('Y-m-d'));
$where = ['l.waktu >= ? AND l.waktu < ? + INTERVAL 1 DAY'];
$par = [$dari, $sampai];
if ($aksi !== '') { $where[] = 'l.aksi = ?'; $par[] = $aksi; }
if ($pid) { $where[] = 'l.petugas_id = ?'; $par[] = $pid; }
$w = implode(' AND ', $where);
$hal = max(1, (int)($_GET['hal'] ?? 1));
$per = 100;
$total = (int)q_val("SELECT COUNT(*) FROM log_aktivitas l WHERE $w", $par);
$rows = q_all("SELECT l.*, p.nama FROM log_aktivitas l LEFT JOIN petugas p ON p.id = l.petugas_id WHERE $w ORDER BY l.id DESC LIMIT $per OFFSET " . (($hal - 1) * $per), $par);
$daftarAksi = q_all('SELECT DISTINCT aksi FROM log_aktivitas ORDER BY aksi');
$daftarPetugas = q_all('SELECT id, nama FROM petugas ORDER BY nama');
$warna = ['void' => 'danger', 'login_gagal' => 'danger', 'sync_ditolak' => 'danger', 'keluar_tanpa_tiket' => 'warn', 'koreksi_plat' => 'warn', 'minta_void' => 'warn', 'ubah_tarif' => 'info'];

layout_atas('Log Aktivitas', 'log.php');
?>
<div class="card">
  <div class="card-h">
    <form class="filters" method="get">
      <label class="f">Dari<input type="date" name="dari" value="<?= e($dari) ?>"></label>
      <label class="f">Sampai<input type="date" name="sampai" value="<?= e($sampai) ?>"></label>
      <label class="f">Aksi<select name="aksi"><option value="">Semua</option><?php foreach ($daftarAksi as $a): ?><option <?= $aksi === $a['aksi'] ? 'selected' : '' ?>><?= e($a['aksi']) ?></option><?php endforeach; ?></select></label>
      <label class="f">Petugas<select name="petugas"><option value="">Semua</option><?php foreach ($daftarPetugas as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $pid === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['nama']) ?></option><?php endforeach; ?></select></label>
      <button class="btn">Filter</button>
    </form>
  </div>
  <div class="card-b flush table-wrap">
    <table>
      <thead><tr><th>Waktu</th><th>Petugas</th><th>Aksi</th><th>Referensi</th><th>Perubahan</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $l): ?>
        <tr>
          <td class="mono"><?= tgl($l['waktu'], 'd/m H:i:s') ?></td>
          <td><?= e($l['nama'] ?? '–') ?></td>
          <td><?= badge($l['aksi'], $warna[$l['aksi']] ?? '') ?></td>
          <td class="mono"><?php if ($l['referensi_id'] && is_uuid($l['referensi_id'])): ?><a href="transaksi.php?id=<?= e($l['referensi_id']) ?>"><?= e(substr($l['referensi_id'], 0, 8)) ?>…</a><?php else: ?><?= e($l['referensi_id']) ?><?php endif; ?></td>
          <td class="mono" style="max-width:420px;word-break:break-word;font-size:12px">
            <?php if ($l['data_sebelum']): ?><span class="muted">sebelum:</span> <?= e($l['data_sebelum']) ?><br><?php endif; ?>
            <?php if ($l['data_sesudah']): ?><span class="muted">sesudah:</span> <?= e($l['data_sesudah']) ?><?php endif; ?>
          </td>
          <td class="mono muted"><?= e($l['ip']) ?></td>
        </tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">Tidak ada aktivitas.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $hal, $per) ?>
</div>
<?php layout_bawah();
