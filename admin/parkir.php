<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

$cari = trim((string)($_GET['plat'] ?? ''));
$jenis = (string)($_GET['jenis'] ?? '');
$where = ["km.status = 'parkir'"];
$par = [];
if ($cari !== '') { $where[] = "REPLACE(km.plat_nomor,' ','') LIKE ?"; $par[] = '%' . str_replace(' ', '', normalisasi_plat($cari)) . '%'; }
if (in_array($jenis, ['motor', 'mobil'], true)) { $where[] = 'km.jenis_kendaraan = ?'; $par[] = $jenis; }
$hal = max(1, (int)($_GET['hal'] ?? 1));
$per = 50;
$total = (int)q_val('SELECT COUNT(*) FROM kendaraan_masuk km WHERE ' . implode(' AND ', $where), $par);
$rows = q_all('SELECT km.*, p.nama petugas FROM kendaraan_masuk km JOIN petugas p ON p.id = km.petugas_id WHERE ' . implode(' AND ', $where)
    . " ORDER BY km.waktu_masuk ASC LIMIT $per OFFSET " . (($hal - 1) * $per), $par);

layout_atas('Sedang Parkir', 'parkir.php');
?>
<div class="card">
  <div class="card-h">
    <form class="filters" method="get">
      <label class="f">Plat<input name="plat" value="<?= e($cari) ?>" placeholder="B 1234 ABC"></label>
      <label class="f">Jenis<select name="jenis"><option value="">Semua</option><option value="motor" <?= $jenis === 'motor' ? 'selected' : '' ?>>Motor</option><option value="mobil" <?= $jenis === 'mobil' ? 'selected' : '' ?>>Mobil</option></select></label>
      <button class="btn">Cari</button>
    </form>
    <span class="muted">Urut dari yang paling lama parkir</span>
  </div>
  <div class="card-b flush table-wrap">
    <table>
      <thead><tr><th>Foto</th><th>Plat</th><th>Jenis</th><th>Masuk</th><th>Lama</th><th class="num">Biaya saat ini</th><th>Pos / Petugas</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $lama = intdiv(time() - strtotime($r['waktu_masuk']), 60);
          try { $biaya = hitung_untuk($r, now(), false)['total']; } catch (Throwable $ex) { $biaya = null; } ?>
        <tr>
          <td><?php if ($r['foto_masuk_url']): ?><a href="<?= e(base_url($r['foto_masuk_url'])) ?>" target="_blank"><img class="thumb" src="<?= e(base_url($r['foto_masuk_url'])) ?>" alt="" loading="lazy"></a><?php endif; ?></td>
          <td><span class="plat"><?= e($r['plat_nomor']) ?></span><?= $r['offline'] ? ' ' . badge('offline', 'warn') : '' ?><?= $r['void_diminta_at'] ? ' ' . badge('minta void', 'danger') : '' ?></td>
          <td><?= e($r['jenis_kendaraan']) ?></td>
          <td><?= tgl($r['waktu_masuk']) ?></td>
          <td><?= $lama >= 1440 ? badge(durasi_teks($lama), 'warn') : e(durasi_teks($lama)) ?></td>
          <td class="num"><?= $biaya === null ? '–' : rupiah($biaya) ?></td>
          <td><?= e($r['pos_masuk']) ?><br><small class="muted"><?= e($r['petugas']) ?></small></td>
          <td><a class="btn sm ghost" href="transaksi.php?id=<?= e($r['id']) ?>">Detail</a></td>
        </tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty">Tidak ada kendaraan yang sedang parkir<?= $cari ? ' dengan plat tersebut' : '' ?>.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $hal, $per) ?>
</div>
<?php layout_bawah();
