<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

$kolom = ['durasi_awal_menit', 'biaya_awal', 'biaya_per_jam', 'maks_per_hari', 'biaya_menginap', 'toleransi_menit'];

if (is_post()) {
    cek_csrf();
    $aksi = $_POST['aksi'] ?? '';
    if ($aksi === 'baru') {
        $jenis = ($_POST['jenis_kendaraan'] ?? '') === 'mobil' ? 'mobil' : 'motor';
        $v = [];
        foreach ($kolom as $k) $v[$k] = max(0, (int)($_POST[$k] ?? 0));
        $jam = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['jam_batas_menginap'] ?? '')) ? $_POST['jam_batas_menginap'] . ':00' : '00:00:00';
        $mulai = strtotime(str_replace('T', ' ', (string)($_POST['berlaku_mulai'] ?? ''))) ?: time();
        if ($v['durasi_awal_menit'] < 1) {
            flash('Durasi awal minimal 1 menit.', 'err');
        } else {
            q('INSERT INTO tarif (jenis_kendaraan, durasi_awal_menit, biaya_awal, biaya_per_jam, maks_per_hari, biaya_menginap, jam_batas_menginap, toleransi_menit, berlaku_mulai, dibuat_oleh)
               VALUES (?,?,?,?,?,?,?,?,?,?)', [$jenis, $v['durasi_awal_menit'], $v['biaya_awal'], $v['biaya_per_jam'], $v['maks_per_hari'], $v['biaya_menginap'], $jam, $v['toleransi_menit'], date('Y-m-d H:i:s', $mulai), admin_id()]);
            $id = (int)db()->lastInsertId();
            log_aktivitas(admin_id(), 'ubah_tarif', $id, null, ['jenis' => $jenis, 'jam_batas_menginap' => $jam, 'berlaku_mulai' => date('Y-m-d H:i:s', $mulai)] + $v);
            flash('Tarif ' . $jenis . ' baru disimpan, berlaku mulai ' . date('d/m/Y H:i', $mulai) . '. Kendaraan yang sudah masuk sebelumnya tetap memakai tarif lama.');
        }
    } elseif ($aksi === 'nonaktif') {
        $t = q_one('SELECT * FROM tarif WHERE id = ?', [(int)$_POST['id']]);
        if ($t) {
            q('UPDATE tarif SET aktif = 1 - aktif WHERE id = ?', [$t['id']]);
            log_aktivitas(admin_id(), 'ubah_tarif', $t['id'], ['aktif' => (int)$t['aktif']], ['aktif' => 1 - (int)$t['aktif']]);
            flash('Status tarif diubah.');
        }
    }
    redirect(self_url());
}

$berlaku = ['motor' => tarif_aktif('motor', now()), 'mobil' => tarif_aktif('mobil', now())];
$semua = q_all('SELECT t.*, p.nama FROM tarif t LEFT JOIN petugas p ON p.id = t.dibuat_oleh ORDER BY t.jenis_kendaraan, t.berlaku_mulai DESC, t.id DESC');

// Simulasi
$sim = null;
if (!empty($_GET['sim_masuk']) && !empty($_GET['sim_keluar'])) {
    $t = tarif_aktif(($_GET['sim_jenis'] ?? '') === 'mobil' ? 'mobil' : 'motor', str_replace('T', ' ', $_GET['sim_masuk']) . ':00');
    if ($t) $sim = hitung_tarif($t, str_replace('T', ' ', $_GET['sim_masuk']) . ':00', str_replace('T', ' ', $_GET['sim_keluar']) . ':00');
}

layout_atas('Tarif', 'tarif.php');
?>
<div class="grid g2">
  <?php foreach ($berlaku as $j => $t): ?>
  <div class="card">
    <div class="card-h"><h2>Tarif <?= e($j) ?> yang berlaku sekarang</h2><?= $t ? badge('#' . $t['id'], 'info') : badge('belum diatur', 'danger') ?></div>
    <div class="card-b">
      <?php if ($t): ?>
      <dl class="kv">
        <dt>Durasi awal</dt><dd><?= (int)$t['durasi_awal_menit'] ?> menit — <b><?= rupiah($t['biaya_awal']) ?></b></dd>
        <dt>Per jam berikutnya</dt><dd><?= rupiah($t['biaya_per_jam']) ?></dd>
        <dt>Maksimal per hari</dt><dd><?= $t['maks_per_hari'] ? rupiah($t['maks_per_hari']) : 'Tanpa batas' ?></dd>
        <dt>Menginap</dt><dd><?= rupiah($t['biaya_menginap']) ?> / malam, lewat pukul <?= substr($t['jam_batas_menginap'], 0, 5) ?></dd>
        <dt>Toleransi</dt><dd><?= (int)$t['toleransi_menit'] ?> menit</dd>
        <dt>Berlaku sejak</dt><dd><?= tgl($t['berlaku_mulai']) ?></dd>
      </dl>
      <?php else: ?><p class="muted">Kendaraan <?= e($j) ?> tidak bisa keluar sebelum tarif diatur.</p><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="grid g2">
  <form class="card" method="post">
    <div class="card-h"><h2>Buat versi tarif baru</h2></div>
    <div class="card-b">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="baru">
      <div class="form-grid">
        <label class="f">Jenis kendaraan<select name="jenis_kendaraan"><option value="motor">Motor</option><option value="mobil">Mobil</option></select></label>
        <label class="f">Berlaku mulai<input type="datetime-local" name="berlaku_mulai" value="<?= date('Y-m-d\TH:i') ?>" required></label>
        <label class="f">Durasi awal (menit)<input type="number" name="durasi_awal_menit" min="1" value="60" required></label>
        <label class="f">Biaya awal (Rp)<input type="number" name="biaya_awal" min="0" step="500" value="2000" required></label>
        <label class="f">Biaya per jam berikutnya<input type="number" name="biaya_per_jam" min="0" step="500" value="1000" required></label>
        <label class="f">Maksimal per hari<small>0 = tanpa batas</small><input type="number" name="maks_per_hari" min="0" step="500" value="10000"></label>
        <label class="f">Biaya menginap / malam<small>0 = tidak dikenakan</small><input type="number" name="biaya_menginap" min="0" step="500" value="5000"></label>
        <label class="f">Jam batas menginap<input type="time" name="jam_batas_menginap" value="00:00"></label>
        <label class="f">Toleransi (menit)<small>Gratis bila keluar sebelum ini</small><input type="number" name="toleransi_menit" min="0" value="10"></label>
      </div>
      <p class="muted">Tarif lama tidak dihapus. Kendaraan dihitung dengan tarif yang berlaku saat <b>waktu masuk</b>.</p>
      <div class="form-foot"><button class="btn">Simpan tarif</button></div>
    </div>
  </form>

  <form class="card" method="get">
    <div class="card-h"><h2>Simulasi perhitungan</h2></div>
    <div class="card-b">
      <div class="form-grid">
        <label class="f">Jenis<select name="sim_jenis"><option value="motor">Motor</option><option value="mobil" <?= ($_GET['sim_jenis'] ?? '') === 'mobil' ? 'selected' : '' ?>>Mobil</option></select></label>
        <label class="f">Masuk<input type="datetime-local" name="sim_masuk" value="<?= e($_GET['sim_masuk'] ?? date('Y-m-d\T08:00')) ?>"></label>
        <label class="f">Keluar<input type="datetime-local" name="sim_keluar" value="<?= e($_GET['sim_keluar'] ?? date('Y-m-d\T14:30')) ?>"></label>
      </div>
      <div class="form-foot"><button class="btn ghost">Hitung</button></div>
      <?php if ($sim): ?>
        <table style="margin-top:14px"><tbody>
        <?php foreach (rincian_baris($sim) as [$l, $v]): ?><tr><td><?= e($l) ?></td><td class="num"><?= rupiah($v) ?></td></tr><?php endforeach; ?>
        </tbody><tfoot><tr><td>Total (durasi <?= e(durasi_teks($sim['durasi_menit'])) ?>, tarif #<?= $sim['tarif_id'] ?>)</td><td class="num"><?= rupiah($sim['total']) ?></td></tr></tfoot></table>
      <?php elseif (!empty($_GET['sim_masuk'])): ?><div class="alert err" style="margin-top:14px">Tidak ada tarif yang berlaku pada waktu masuk tersebut.</div><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-h"><h2>Riwayat versi tarif</h2></div>
  <div class="card-b flush table-wrap">
    <table>
      <thead><tr><th>#</th><th>Jenis</th><th>Berlaku mulai</th><th>Awal</th><th class="num">Per jam</th><th class="num">Maks/hari</th><th class="num">Menginap</th><th>Toleransi</th><th>Dibuat</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($semua as $t): ?>
        <tr>
          <td>#<?= (int)$t['id'] ?></td><td><?= e($t['jenis_kendaraan']) ?></td><td><?= tgl($t['berlaku_mulai']) ?></td>
          <td><?= (int)$t['durasi_awal_menit'] ?> mnt / <?= rupiah($t['biaya_awal']) ?></td><td class="num"><?= rupiah($t['biaya_per_jam']) ?></td>
          <td class="num"><?= $t['maks_per_hari'] ? rupiah($t['maks_per_hari']) : '∞' ?></td><td class="num"><?= rupiah($t['biaya_menginap']) ?> <small class="muted">@<?= substr($t['jam_batas_menginap'], 0, 5) ?></small></td>
          <td><?= (int)$t['toleransi_menit'] ?> mnt</td><td><small><?= tgl($t['created_at']) ?><br><span class="muted"><?= e($t['nama'] ?? 'sistem') ?></span></small></td>
          <td><?= ($berlaku[$t['jenis_kendaraan']]['id'] ?? null) == $t['id'] ? badge('berlaku', 'ok') : ($t['aktif'] ? (strtotime($t['berlaku_mulai']) > time() ? badge('terjadwal', 'info') : badge('versi lama')) : badge('nonaktif', 'danger')) ?></td>
          <td><form method="post" data-confirm="<?= $t['aktif'] ? 'Nonaktifkan' : 'Aktifkan kembali' ?> tarif #<?= (int)$t['id'] ?>? Kendaraan yang masuk di periode tarif ini akan memakai versi aktif sebelumnya."><?= csrf_field() ?><input type="hidden" name="aksi" value="nonaktif"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn sm ghost"><?= $t['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_bawah();
