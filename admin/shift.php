<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

// Admin dapat menutup paksa shift yang tertinggal (mis. HP petugas mati).
if (is_post() && ($_POST['aksi'] ?? '') === 'tutup_paksa') {
    cek_csrf();
    $s = q_one('SELECT * FROM shift WHERE id = ? AND waktu_tutup IS NULL', [(int)$_POST['id']]);
    if ($s) {
        try {
            $setor = isset($_POST['setor']) && $_POST['setor'] !== '' ? (int)$_POST['setor'] : null;
            $hasil = tutup_shift($s, $setor, 'Ditutup admin: ' . mb_substr(trim((string)($_POST['catatan'] ?? '')), 0, 200));
            log_aktivitas(admin_id(), 'tutup_shift_admin', $s['id'], null, $hasil + ['pos' => $s['pos']]);
            flash('Shift ditutup.');
        } catch (ParkirException $ex) {
            flash($ex->getMessage(), 'err');
        }
    }
    redirect(self_url());
}

$dari = (string)($_GET['dari'] ?? date('Y-m-d', strtotime('-6 days')));
$sampai = (string)($_GET['sampai'] ?? date('Y-m-d'));
$rows = q_all("SELECT s.*, p.nama FROM shift s JOIN petugas p ON p.id = s.petugas_id
    WHERE s.waktu_tutup IS NULL OR (s.waktu_buka >= ? AND s.waktu_buka < ? + INTERVAL 1 DAY)
    ORDER BY s.waktu_tutup IS NULL DESC, s.waktu_buka DESC LIMIT 300", [$dari, $sampai]);

layout_atas('Shift & Setoran', 'shift.php');
?>
<div class="card">
  <div class="card-h">
    <form class="filters" method="get">
      <label class="f">Dari<input type="date" name="dari" value="<?= e($dari) ?>"></label>
      <label class="f">Sampai<input type="date" name="sampai" value="<?= e($sampai) ?>"></label>
      <button class="btn">Tampilkan</button>
    </form>
    <span class="muted">Shift yang masih berjalan selalu ditampilkan di atas</span>
  </div>
  <div class="card-b flush table-wrap">
    <table>
      <thead><tr><th>Petugas</th><th>Pos</th><th>Buka</th><th>Tutup</th><th class="num">Masuk</th><th class="num">Keluar</th><th class="num">Cash sistem</th><th class="num">QRIS</th><th class="num">Setoran</th><th class="num">Selisih</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $s): $rs = ringkasan_shift((int)$s['id']); $buka = $s['waktu_tutup'] === null; $posMasuk = $s['pos'] === 'masuk'; ?>
        <tr>
          <td><?= e($s['nama']) ?></td>
          <td><?= badge($s['pos'], $s['pos'] === 'masuk' ? 'info' : 'ok') ?> <?= e($s['pos_nama']) ?></td>
          <td><?= tgl($s['waktu_buka']) ?></td>
          <td><?= $buka ? badge('berjalan', 'info') : tgl($s['waktu_tutup']) ?></td>
          <td class="num"><?= $rs['kendaraan_masuk'] ?><?php if ($posMasuk && ($rs['tiket']['void'] || $rs['tiket']['offline'])): ?><br><small class="muted"><?= $rs['tiket']['void'] ? $rs['tiket']['void'] . ' void ' : '' ?><?= $rs['tiket']['offline'] ? $rs['tiket']['offline'] . ' offline' : '' ?></small><?php endif; ?></td>
          <td class="num"><?= $rs['kendaraan_keluar'] ?></td>
          <?php if ($posMasuk): ?>
          <td class="num muted" colspan="4" style="text-align:center">Pos masuk tidak menerima uang · rekap tiket: <?= $rs['tiket']['motor'] ?> motor, <?= $rs['tiket']['mobil'] ?> mobil</td>
          <?php else: ?>
          <td class="num"><?= rupiah($buka ? $rs['total_cash'] : $s['total_cash_sistem']) ?></td>
          <td class="num"><?= rupiah($rs['total_qris']) ?></td>
          <td class="num"><?= $buka ? '–' : rupiah($s['total_cash_setor']) ?></td>
          <td class="num"><?php if (!$buka): $sl = (int)$s['selisih']; echo $sl === 0 ? badge('pas', 'ok') : badge(($sl > 0 ? '+' : '') . rupiah($sl), $sl < 0 ? 'danger' : 'warn'); endif; ?></td>
          <?php endif; ?>
          <td>
            <?php if ($buka): ?>
            <details><summary class="btn sm ghost">Tutup paksa</summary>
              <form method="post" style="display:grid;gap:6px;margin-top:6px;min-width:200px" data-confirm="Tutup shift ini atas nama admin?">
                <?= csrf_field() ?><input type="hidden" name="aksi" value="tutup_paksa"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <?php if (!$posMasuk): ?><input type="number" name="setor" min="0" placeholder="Uang diterima" required><?php endif; ?>
                <input name="catatan" placeholder="Alasan">
                <button class="btn sm danger">Tutup shift</button>
              </form>
            </details>
            <?php elseif ($s['catatan']): ?><span class="muted" title="<?= e($s['catatan']) ?>">📝</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="11" class="empty">Tidak ada shift.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_bawah();
