<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

$kunci = ['denda_tanpa_tiket_motor', 'denda_tanpa_tiket_mobil', 'qris_nama_merchant', 'ocr_url', 'ocr_timeout', 'ocr_ambang', 'retensi_foto_hari'];

if (is_post()) {
    cek_csrf();
    $sebelum = [];
    $sesudah = [];
    foreach ($kunci as $k) {
        if (!isset($_POST[$k])) continue;
        $v = trim((string)$_POST[$k]);
        if (in_array($k, ['denda_tanpa_tiket_motor', 'denda_tanpa_tiket_mobil', 'ocr_timeout', 'retensi_foto_hari'], true)) $v = (string)max(0, (int)$v);
        if ($k === 'ocr_timeout') $v = (string)max(1, min(10, (int)$v));
        if ($k === 'ocr_ambang') $v = (string)round(max(0, min(100, (float)str_replace(',', '.', $v))) / 100, 2);
        if ($v !== setting($k)) { $sebelum[$k] = setting($k); $sesudah[$k] = $v; }
        set_setting($k, $v);
    }
    // Token OCR hanya diubah bila diisi (tidak ditampilkan kembali).
    if (trim((string)($_POST['ocr_token'] ?? '')) !== '') {
        set_setting('ocr_token', trim((string)$_POST['ocr_token']));
        $sesudah['ocr_token'] = '(diubah)';
    }
    if (!empty($_POST['hapus_ocr_token'])) { set_setting('ocr_token', ''); $sesudah['ocr_token'] = '(dihapus)'; }

    if (!empty($_FILES['qris']['name'])) {
        $f = $_FILES['qris'];
        $info = $f['error'] === UPLOAD_ERR_OK ? @getimagesize($f['tmp_name']) : false;
        if ($info && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) && $f['size'] < 3 * 1024 * 1024) {
            $nama = 'uploads/qris/qris.' . ($info[2] === IMAGETYPE_PNG ? 'png' : 'jpg');
            foreach (glob(APP_ROOT . '/uploads/qris/qris.*') as $lama) @unlink($lama);
            move_uploaded_file($f['tmp_name'], APP_ROOT . '/' . $nama);
            set_setting('qris_gambar', $nama);
            $sesudah['qris_gambar'] = '(gambar baru)';
        } else {
            flash('Gambar QRIS harus PNG/JPG maksimal 3 MB.', 'err');
        }
    }
    if ($sesudah) log_aktivitas(admin_id(), 'ubah_pengaturan', null, $sebelum, $sesudah);
    flash('Pengaturan disimpan.');
    redirect('pengaturan.php');
}

$tes = null;
if (isset($_GET['tes_ocr'])) {
    $tes = ocr_aktif() ? 'OCR aktif. Coba ambil foto di pos masuk untuk menguji pembacaan.' : 'OCR nonaktif: URL kosong atau token cloud belum diisi.';
}
$qris = setting('qris_gambar');

layout_atas('Pengaturan', 'pengaturan.php');
?>
<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="grid g2">
    <div class="card">
      <div class="card-h"><h2>Denda & tampilan resi</h2><a class="btn sm ghost" href="resi.php">Atur tampilan resi →</a></div>
      <div class="card-b" style="display:grid;gap:12px">
        <p class="muted" style="margin:0">Nama lokasi, alamat, telepon, ketentuan tiket, penutup struk, dan lebar kertas (56/58 mm atau 80 mm) diatur di menu <a href="resi.php">Tampilan Resi</a>, lengkap dengan pratinjau.</p>
        <div class="form-grid">
          <label class="f">Denda tanpa tiket — motor<input type="number" min="0" step="500" name="denda_tanpa_tiket_motor" value="<?= e(setting('denda_tanpa_tiket_motor')) ?>"></label>
          <label class="f">Denda tanpa tiket — mobil<input type="number" min="0" step="500" name="denda_tanpa_tiket_mobil" value="<?= e(setting('denda_tanpa_tiket_mobil')) ?>"></label>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-h"><h2>QRIS statis</h2></div>
      <div class="card-b" style="display:grid;gap:12px;grid-template-columns:1fr 140px;align-items:start">
        <div style="display:grid;gap:12px">
          <label class="f">Nama merchant<input name="qris_nama_merchant" value="<?= e(setting('qris_nama_merchant')) ?>"></label>
          <label class="f">Gambar QRIS<small>PNG/JPG dari aplikasi merchant. Ditampilkan di HP petugas saat pelanggan memilih QRIS.</small><input type="file" name="qris" accept="image/png,image/jpeg"></label>
        </div>
        <?php if ($qris): ?><img src="<?= e(base_url($qris)) ?>?v=<?= @filemtime(APP_ROOT . '/' . $qris) ?>" alt="QRIS" style="width:140px;border-radius:8px;border:1px solid var(--border)"><?php else: ?><div class="empty" style="padding:20px;border:1px dashed var(--border);border-radius:8px">Belum ada</div><?php endif; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-h"><h2>OCR plat nomor</h2><a class="btn sm ghost" href="?tes_ocr=1">Cek status</a></div>
      <div class="card-b" style="display:grid;gap:12px">
        <?php if ($tes): ?><div class="alert <?= ocr_aktif() ? '' : 'warn' ?>"><?= e($tes) ?></div><?php endif; ?>
        <label class="f">URL API<small>Cloud: https://api.platerecognizer.com/v1/plate-reader/ — atau SDK lokal, mis. http://127.0.0.1:8080/v1/plate-reader/. Kosongkan untuk mematikan OCR.</small><input name="ocr_url" value="<?= e(setting('ocr_url')) ?>"></label>
        <label class="f">Token API<small><?= setting('ocr_token') ? 'Token tersimpan (tidak ditampilkan). Isi untuk mengganti.' : 'Belum ada token.' ?></small><input name="ocr_token" type="password" autocomplete="off" placeholder="<?= setting('ocr_token') ? '••••••••' : '' ?>"></label>
        <?php if (setting('ocr_token')): ?><label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="hapus_ocr_token" value="1"> Hapus token</label><?php endif; ?>
        <label class="f">Batas waktu (detik)<small>Lewat dari ini petugas langsung mengetik manual</small><input type="number" min="1" max="10" name="ocr_timeout" value="<?= e(setting('ocr_timeout', '3')) ?>"></label>
        <label class="f">Ambang keyakinan (%)<small>Di bawah nilai ini, atau bila format plat tidak dikenali, petugas diberi peringatan kuning "Periksa! OCR ragu"</small><input type="number" min="0" max="100" step="1" name="ocr_ambang" value="<?= e((string)round((float)setting('ocr_ambang', '0.9') * 100)) ?>"></label>
      </div>
    </div>
    <div class="card">
      <div class="card-h"><h2>Penyimpanan foto</h2></div>
      <div class="card-b" style="display:grid;gap:12px">
        <label class="f">Retensi foto (hari)<small>Foto lebih lama dari ini dihapus oleh tugas terjadwal <code>cli/maintenance.php</code>. 0 = tidak dihapus.</small><input type="number" min="0" name="retensi_foto_hari" value="<?= e(setting('retensi_foto_hari', '60')) ?>"></label>
        <?php
        $total = 0; $jml = 0;
        $it = is_dir(APP_ROOT . '/uploads/foto') ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT . '/uploads/foto', FilesystemIterator::SKIP_DOTS)) : [];
        foreach ($it as $f) { if ($f->isFile() && $f->getFilename() !== '.htaccess') { $total += $f->getSize(); $jml++; } }
        $bebas = @disk_free_space(APP_ROOT);
        ?>
        <p class="muted" style="margin:0"><?= number_format($jml, 0, ',', '.') ?> foto · <?= number_format($total / 1048576, 1, ',', '.') ?> MB terpakai<?= $bebas ? ' · ruang disk bebas ' . number_format($bebas / 1073741824, 1, ',', '.') . ' GB' : '' ?></p>
      </div>
    </div>
  </div>
  <div class="form-foot"><button class="btn">Simpan pengaturan</button></div>
</form>
<?php layout_bawah();
