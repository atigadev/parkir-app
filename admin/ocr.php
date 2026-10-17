<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

$hari = max(7, min(90, (int)($_GET['hari'] ?? 30)));
$rows = q_all("SELECT DATE(waktu_masuk) d, COUNT(*) total, SUM(plat_hasil_ocr IS NOT NULL) ocr, SUM(plat_dikoreksi) koreksi
    FROM kendaraan_masuk WHERE waktu_masuk >= CURDATE() - INTERVAL ? DAY GROUP BY d ORDER BY d DESC", [$hari - 1]);
$t = ['total' => 0, 'ocr' => 0, 'koreksi' => 0];
foreach ($rows as $r) foreach ($t as $k => $_) $t[$k] += (int)$r[$k];
$akurasi = $t['ocr'] ? 100 * ($t['ocr'] - $t['koreksi']) / $t['ocr'] : null;

$contoh = q_all('SELECT id, plat_hasil_ocr, plat_nomor, foto_masuk_url, waktu_masuk FROM kendaraan_masuk WHERE plat_dikoreksi = 1 ORDER BY waktu_masuk DESC LIMIT 20');

layout_atas('Kualitas OCR', 'ocr.php');
?>
<?php if (!ocr_aktif()): ?><div class="alert warn">OCR belum aktif. Isi token Plate Recognizer di <a href="pengaturan.php">Pengaturan</a>; sampai saat itu petugas mengetik plat secara manual.</div><?php endif; ?>
<div class="grid g3" style="margin-bottom:18px">
  <div class="stat accent"><div class="label">Akurasi OCR (<?= $hari ?> hari)</div><div class="value"><?= $akurasi === null ? '–' : number_format($akurasi, 1, ',', '.') . '%' ?></div><div class="sub">Hasil OCR yang tidak dikoreksi petugas</div></div>
  <div class="stat"><div class="label">Terbaca OCR</div><div class="value"><?= $t['ocr'] ?> / <?= $t['total'] ?></div><div class="sub"><?= $t['total'] ? round(100 * $t['ocr'] / $t['total']) : 0 ?>% kendaraan terisi otomatis</div></div>
  <div class="stat"><div class="label">Dikoreksi petugas</div><div class="value"><?= $t['koreksi'] ?></div></div>
</div>
<div class="grid g2">
  <div class="card">
    <div class="card-h"><h2>Per hari</h2>
      <form method="get" class="filters"><select name="hari" onchange="this.form.submit()"><?php foreach ([7, 30, 90] as $h): ?><option value="<?= $h ?>" <?= $h === $hari ? 'selected' : '' ?>><?= $h ?> hari</option><?php endforeach; ?></select></form></div>
    <div class="card-b flush table-wrap">
      <table>
        <thead><tr><th>Tanggal</th><th class="num">Masuk</th><th class="num">Terbaca</th><th class="num">Dikoreksi</th><th style="width:30%">% dikoreksi</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $pct = $r['ocr'] ? 100 * $r['koreksi'] / $r['ocr'] : 0; ?>
          <tr><td><?= tgl($r['d'], 'D, d M') ?></td><td class="num"><?= (int)$r['total'] ?></td><td class="num"><?= (int)$r['ocr'] ?></td><td class="num"><?= (int)$r['koreksi'] ?></td>
            <td><div style="display:flex;gap:8px;align-items:center"><div class="meter" style="flex:1"><i style="width:<?= $pct ?>%;background:<?= $pct > 20 ? 'var(--danger)' : ($pct > 10 ? 'var(--warn)' : 'var(--ok)') ?>"></i></div><span class="num" style="width:44px"><?= round($pct) ?>%</span></div></td></tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="empty">Belum ada data.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-h"><h2>Koreksi terbaru</h2><span class="muted">Untuk melihat pola salah baca</span></div>
    <div class="card-b flush table-wrap">
      <table>
        <thead><tr><th>Foto</th><th>OCR</th><th>Dikoreksi jadi</th><th>Waktu</th></tr></thead>
        <tbody>
        <?php foreach ($contoh as $c): ?>
          <tr><td><?php if ($c['foto_masuk_url']): ?><a href="<?= e(base_url($c['foto_masuk_url'])) ?>" data-foto="<?= e('OCR: ' . $c['plat_hasil_ocr'] . ' → dikoreksi: ' . $c['plat_nomor']) ?>"><img class="thumb" src="<?= e(base_url($c['foto_masuk_url'])) ?>" alt="" loading="lazy"></a><?php endif; ?></td>
            <td><span class="plat" style="text-decoration:line-through;opacity:.7"><?= e($c['plat_hasil_ocr']) ?></span></td><td><span class="plat"><?= e($c['plat_nomor']) ?></span></td><td><?= tgl($c['waktu_masuk']) ?></td></tr>
        <?php endforeach; if (!$contoh): ?><tr><td colspan="4" class="empty">Belum ada koreksi.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php layout_bawah();
