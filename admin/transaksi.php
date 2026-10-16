<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

if (is_post()) {
    cek_csrf();
    $id = (string)($_POST['id'] ?? '');
    try {
        switch ($_POST['aksi'] ?? '') {
            case 'void':
                void_tiket($id, admin_id(), (string)($_POST['alasan'] ?? ''));
                flash('Tiket berhasil di-void.');
                break;
            case 'tolak_void':
                $km = q_one('SELECT void_alasan FROM kendaraan_masuk WHERE id = ?', [$id]);
                q('UPDATE kendaraan_masuk SET void_diminta_at = NULL, void_diminta_oleh = NULL, void_alasan = NULL WHERE id = ?', [$id]);
                log_aktivitas(admin_id(), 'tolak_void', $id, ['alasan' => $km['void_alasan'] ?? null], null);
                flash('Permintaan void ditolak.');
                break;
        }
    } catch (ParkirException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect(self_url());
}

// ------------------------------------------------------------- Detail
if (!empty($_GET['id'])) {
    $km = masuk_by_id((string)$_GET['id']);
    if (!$km) { flash('Data tidak ditemukan.', 'err'); redirect('transaksi.php'); }
    $tkRow = q_one('SELECT id FROM transaksi_keluar WHERE kendaraan_masuk_id = ?', [$km['id']]);
    $tk = $tkRow ? transaksi_keluar_lengkap((int)$tkRow['id']) : null;
    $nama = function ($id) { return $id ? (string)q_val('SELECT nama FROM petugas WHERE id = ?', [$id]) : '–'; };
    $log = q_all('SELECT l.*, p.nama FROM log_aktivitas l LEFT JOIN petugas p ON p.id = l.petugas_id WHERE l.referensi_id = ? ORDER BY l.waktu', [$km['id']]);

    layout_atas('Detail Transaksi', 'transaksi.php');
    ?>
    <p><a href="transaksi.php">‹ Kembali ke daftar</a></p>
    <div class="grid g2">
      <div class="card">
        <div class="card-h"><h2><span class="plat" style="font-size:18px"><?= e($km['plat_nomor']) ?></span></h2><?= badge_status($km['status']) ?></div>
        <div class="card-b">
          <dl class="kv">
            <dt>Jenis</dt><dd><?= e($km['jenis_kendaraan']) ?></dd>
            <dt>Masuk</dt><dd><?= tgl($km['waktu_masuk'], 'd/m/Y H:i:s') ?> · <?= e($km['pos_masuk']) ?> · <?= e($km['petugas_nama']) ?></dd>
            <dt>Hasil OCR</dt><dd><?= $km['plat_hasil_ocr'] ? '<span class="plat">' . e($km['plat_hasil_ocr']) . '</span>' . ($km['plat_dikoreksi'] ? ' ' . badge('dikoreksi petugas', 'warn') : ' ' . badge('tepat', 'ok')) : '<span class="muted">Input manual</span>' ?></dd>
            <dt>Kode QR</dt><dd class="mono" style="word-break:break-all"><?= e($km['kode_qr']) ?></dd>
            <dt>ID</dt><dd class="mono"><?= e($km['id']) ?><?= $km['offline'] ? ' ' . badge('dibuat offline', 'warn') : '' ?></dd>
            <?php if ($tk): ?>
            <dt>Keluar</dt><dd><?= tgl($tk['waktu_keluar'], 'd/m/Y H:i:s') ?> · <?= e($tk['petugas_nama']) ?></dd>
            <dt>Durasi</dt><dd><?= e(durasi_teks((int)$tk['durasi_menit'])) ?></dd>
            <dt>Pembayaran</dt><dd><b><?= rupiah($tk['total_biaya']) ?></b> · <?= strtoupper(e($tk['metode_bayar'])) ?>
              <?= $tk['metode_bayar'] === 'qris' ? ($tk['qris_terverifikasi'] ? badge('terverifikasi', 'ok') : badge('belum direkonsiliasi', 'warn')) : '' ?>
              <?= $tk['tanpa_tiket'] ? badge('tanpa tiket', 'danger') : '' ?><?= $tk['offline'] ? badge('offline', 'warn') : '' ?></dd>
            <?php if ($tk['catatan']): ?><dt>Catatan</dt><dd><?= e($tk['catatan']) ?></dd><?php endif; ?>
            <?php endif; ?>
            <?php if ($km['status'] === 'void'): ?>
            <dt>Void</dt><dd><?= tgl($km['void_at']) ?> oleh <?= e($nama($km['void_oleh'])) ?><br><b>Alasan:</b> <?= e($km['void_alasan']) ?></dd>
            <?php endif; ?>
          </dl>
        </div>
        <?php if ($tk): ?>
        <div class="card-b flush">
          <table><thead><tr><th>Rincian biaya</th><th class="num">Jumlah</th></tr></thead><tbody>
          <?php foreach ($tk['rincian_baris'] as [$l, $v]): ?><tr><td><?= e($l) ?></td><td class="num"><?= rupiah($v) ?></td></tr><?php endforeach; ?>
          </tbody><tfoot><tr><td>Total</td><td class="num"><?= rupiah($tk['total_biaya']) ?></td></tr></tfoot></table>
          <p class="muted" style="padding:0 14px 12px;margin:0">Tarif #<?= (int)$tk['rincian']['tarif_id'] ?>: awal <?= (int)$tk['rincian']['tarif']['durasi_awal_menit'] ?> mnt <?= rupiah($tk['rincian']['tarif']['biaya_awal']) ?>, per jam <?= rupiah($tk['rincian']['tarif']['biaya_per_jam']) ?>, maks/hari <?= rupiah($tk['rincian']['tarif']['maks_per_hari']) ?>, menginap <?= rupiah($tk['rincian']['tarif']['biaya_menginap']) ?>, toleransi <?= (int)$tk['rincian']['tarif']['toleransi_menit'] ?> mnt.</p>
        </div>
        <?php endif; ?>
      </div>

      <div>
        <div class="card"><div class="card-h"><h2>Foto masuk & keluar</h2></div>
          <div class="card-b photo-pair">
            <figure style="margin:0"><?php if ($km['foto_masuk_url']): ?><a href="<?= e(base_url($km['foto_masuk_url'])) ?>" target="_blank"><img src="<?= e(base_url($km['foto_masuk_url'])) ?>" alt="Foto masuk"></a><?php else: ?><div class="empty">Tidak ada foto</div><?php endif; ?><figcaption class="muted">Masuk</figcaption></figure>
            <figure style="margin:0"><?php if ($tk && $tk['foto_keluar_url']): ?><a href="<?= e(base_url($tk['foto_keluar_url'])) ?>" target="_blank"><img src="<?= e(base_url($tk['foto_keluar_url'])) ?>" alt="Foto keluar"></a><?php else: ?><div class="empty">Tidak ada foto</div><?php endif; ?><figcaption class="muted">Keluar</figcaption></figure>
          </div>
        </div>

        <?php if ($km['status'] !== 'void'): ?>
        <div class="card"><div class="card-h"><h2>Void tiket</h2></div>
          <div class="card-b">
            <?php if ($km['void_diminta_at']): ?>
              <div class="alert warn"><b><?= e($nama($km['void_diminta_oleh'])) ?></b> meminta void pada <?= tgl($km['void_diminta_at']) ?>: “<?= e($km['void_alasan']) ?>”</div>
            <?php endif; ?>
            <form method="post" data-confirm="Void tiket ini? Tindakan tercatat di log dan tidak dapat dibatalkan.">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($km['id']) ?>"><input type="hidden" name="aksi" value="void">
              <label class="f">Alasan void (wajib)<textarea name="alasan" rows="2" required><?= e($km['void_alasan'] ?? '') ?></textarea></label>
              <div class="form-foot"><button class="btn danger"><?= $km['void_diminta_at'] ? 'Setujui void' : 'Void tiket' ?></button></div>
            </form>
            <?php if ($km['void_diminta_at']): ?>
            <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($km['id']) ?>"><input type="hidden" name="aksi" value="tolak_void"><button class="btn ghost">Tolak permintaan</button></form>
            <?php endif; ?>
            <p class="muted" style="margin-bottom:0">Data tidak pernah dihapus. Void mengeluarkan transaksi dari laporan pendapatan dan rekap shift.</p>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($log): ?>
        <div class="card"><div class="card-h"><h2>Riwayat aktivitas</h2></div>
          <div class="card-b flush"><table><tbody>
          <?php foreach ($log as $l): ?><tr><td><?= tgl($l['waktu']) ?></td><td><?= badge($l['aksi']) ?></td><td><?= e($l['nama'] ?? '–') ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    layout_bawah();
    exit;
}

// ------------------------------------------------------------- Daftar
$dari = (string)($_GET['dari'] ?? date('Y-m-d'));
$sampai = (string)($_GET['sampai'] ?? date('Y-m-d'));
$plat = trim((string)($_GET['plat'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$voidFilter = (string)($_GET['void'] ?? '');

$where = [];
$par = [];
if ($voidFilter === 'minta') {
    $where[] = "km.void_diminta_at IS NOT NULL AND km.status <> 'void'";
} else {
    $where[] = 'km.waktu_masuk >= ? AND km.waktu_masuk < ? + INTERVAL 1 DAY';
    $par[] = $dari; $par[] = $sampai;
}
if ($plat !== '') { $where[] = "REPLACE(km.plat_nomor,' ','') LIKE ?"; $par[] = '%' . str_replace(' ', '', normalisasi_plat($plat)) . '%'; }
if (in_array($status, ['parkir', 'keluar', 'void'], true)) { $where[] = 'km.status = ?'; $par[] = $status; }
if ($status === 'tanpa_tiket') { $where[] = 'tk.tanpa_tiket = 1'; }

$w = implode(' AND ', $where);
$hal = max(1, (int)($_GET['hal'] ?? 1));
$per = 50;
$total = (int)q_val("SELECT COUNT(*) FROM kendaraan_masuk km LEFT JOIN transaksi_keluar tk ON tk.kendaraan_masuk_id = km.id WHERE $w", $par);
$rows = q_all("SELECT km.*, tk.waktu_keluar, tk.total_biaya, tk.metode_bayar, tk.tanpa_tiket, pm.nama petugas_masuk
    FROM kendaraan_masuk km LEFT JOIN transaksi_keluar tk ON tk.kendaraan_masuk_id = km.id JOIN petugas pm ON pm.id = km.petugas_id
    WHERE $w ORDER BY km.waktu_masuk DESC LIMIT $per OFFSET " . (($hal - 1) * $per), $par);

layout_atas('Transaksi & Void', 'transaksi.php');
?>
<div class="card">
  <div class="card-h">
    <form class="filters" method="get">
      <label class="f">Dari<input type="date" name="dari" value="<?= e($dari) ?>"></label>
      <label class="f">Sampai<input type="date" name="sampai" value="<?= e($sampai) ?>"></label>
      <label class="f">Plat<input name="plat" value="<?= e($plat) ?>" placeholder="B 1234"></label>
      <label class="f">Status<select name="status">
        <option value="">Semua</option>
        <?php foreach (['parkir' => 'Parkir', 'keluar' => 'Keluar', 'void' => 'Void', 'tanpa_tiket' => 'Keluar tanpa tiket'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= $status === $v ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select></label>
      <button class="btn">Tampilkan</button>
    </form>
    <a class="btn sm <?= $voidFilter === 'minta' ? '' : 'ghost' ?>" href="?void=minta">Permintaan void</a>
  </div>
  <div class="card-b flush table-wrap">
    <table>
      <thead><tr><th>Plat</th><th>Jenis</th><th>Masuk</th><th>Keluar</th><th class="num">Total</th><th>Bayar</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="plat"><?= e($r['plat_nomor']) ?></span></td>
          <td><?= e($r['jenis_kendaraan']) ?></td>
          <td><?= tgl($r['waktu_masuk']) ?><br><small class="muted"><?= e($r['pos_masuk']) ?> · <?= e($r['petugas_masuk']) ?></small></td>
          <td><?= tgl($r['waktu_keluar']) ?></td>
          <td class="num"><?= $r['total_biaya'] !== null ? rupiah($r['total_biaya']) : '–' ?></td>
          <td><?= $r['metode_bayar'] ? strtoupper(e($r['metode_bayar'])) : '–' ?><?= $r['tanpa_tiket'] ? ' ' . badge('tanpa tiket', 'danger') : '' ?></td>
          <td><?= badge_status($r['status']) ?><?= $r['void_diminta_at'] && $r['status'] !== 'void' ? ' ' . badge('minta void', 'danger') : '' ?></td>
          <td><a class="btn sm ghost" href="?id=<?= e($r['id']) ?>">Detail</a></td>
        </tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="empty">Tidak ada transaksi.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?= pagination($total, $hal, $per) ?>
</div>
<?php layout_bawah();
