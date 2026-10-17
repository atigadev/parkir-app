<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

$bawaan = resi_config_bawaan();

if (is_post()) {
    cek_csrf();
    $sebelum = ['resi' => resi_config(), 'nama_lokasi' => setting('nama_lokasi')];
    if (($_POST['aksi'] ?? '') === 'reset') {
        set_setting('resi_config', '');
        log_aktivitas(admin_id(), 'ubah_resi', null, $sebelum, ['resi' => 'dikembalikan ke bawaan']);
        flash('Tampilan resi dikembalikan ke bawaan. Teks lokasi, ketentuan & penutup tidak diubah.');
        redirect('resi.php');
    }

    // Susun konfigurasi mengikuti struktur bawaan: centang = boolean, isian = teks.
    $c = $bawaan;
    $c['kertas'] = (int)($_POST['kertas'] ?? 58) === 80 ? 80 : 58;
    $c['feed'] = max(1, min(10, (int)($_POST['feed'] ?? 4)));
    $c['header_tambahan'] = mb_substr(trim((string)($_POST['header_tambahan'] ?? '')), 0, 120);
    foreach (['masuk', 'keluar'] as $jenis) {
        $in = (array)($_POST[$jenis] ?? []);
        foreach ($bawaan[$jenis] as $k => $v) {
            $c[$jenis][$k] = is_bool($v) ? !empty($in[$k]) : mb_substr(trim((string)($in[$k] ?? '')), 0, 80);
        }
    }
    $nama = trim((string)($_POST['nama_lokasi'] ?? ''));
    if ($nama === '') {
        flash('Nama lokasi wajib diisi.', 'err');
        redirect('resi.php');
    }
    set_setting('resi_config', json_encode($c, JSON_UNESCAPED_UNICODE));
    set_setting('nama_lokasi', mb_substr($nama, 0, 40));
    set_setting('alamat_lokasi', mb_substr(trim((string)($_POST['alamat_lokasi'] ?? '')), 0, 80));
    set_setting('telepon_lokasi', mb_substr(trim((string)($_POST['telepon_lokasi'] ?? '')), 0, 32));
    set_setting('footer_resi', mb_substr(trim(str_replace("\r", '', (string)($_POST['footer_resi'] ?? ''))), 0, 600));
    set_setting('footer_struk', mb_substr(trim((string)($_POST['footer_struk'] ?? '')), 0, 64));
    log_aktivitas(admin_id(), 'ubah_resi', null, $sebelum, ['resi' => $c, 'nama_lokasi' => $nama]);
    flash('Tampilan resi disimpan. HP petugas memakai pengaturan baru setelah aplikasi dibuka ulang atau tersambung kembali.');
    redirect('resi.php');
}

$r = resi_config();
$tarif = q_all('SELECT * FROM tarif WHERE aktif = 1 ORDER BY jenis_kendaraan, berlaku_mulai');
$cfgDasar = [
    'tarif' => $tarif,
    'denda' => ['motor' => denda_tanpa_tiket('motor'), 'mobil' => denda_tanpa_tiket('mobil')],
];

function cek(string $nama, bool $nilai, string $label, string $ket = ''): string
{
    return '<label class="cekbox"><input type="checkbox" name="' . e($nama) . '" value="1"' . ($nilai ? ' checked' : '') . '><span>' . e($label)
        . ($ket !== '' ? '<small>' . e($ket) . '</small>' : '') . '</span></label>';
}

layout_atas('Tampilan Resi', 'resi.php');
?>
<link rel="stylesheet" href="<?= e(base_url('pwa/struk.css')) ?>?v=7">
<style>
  .resi-grid { display: grid; grid-template-columns: minmax(0, 1fr) 470px; gap: 18px; align-items: start; }
  .resi-grid > * { min-width: 0; }
  .pratinjau { position: sticky; top: 12px; }
  .pratinjau .kertas-bg { background: #c9ced6; border-radius: 0 0 10px 10px; padding: 18px 12px 26px; max-height: calc(100vh - 170px); overflow: auto; }
  .pratinjau .kertas-bg canvas { display: block; margin: 0 auto; background: #fff; box-shadow: 0 6px 24px rgba(0,0,0,.25); max-width: 100%; height: auto; image-rendering: pixelated; }
  .seg-admin { display: inline-flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
  .seg-admin button { border: 0; background: var(--surface); color: var(--text); font: inherit; font-weight: 600; padding: 6px 12px; cursor: pointer; }
  .seg-admin button + button { border-left: 1px solid var(--border); }
  .seg-admin button.on { background: var(--accent); color: #fff; }
  .cek-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px 16px; }
  .cekbox { display: flex; gap: 9px; align-items: flex-start; cursor: pointer; padding: 6px 0; }
  .cekbox input { margin-top: 2px; flex: none; }
  .cekbox small { display: block; color: var(--muted); font-size: 12px; }
  .kertas-pilih { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .kertas-pilih label { border: 2px solid var(--border); border-radius: 10px; padding: 12px; cursor: pointer; display: flex; gap: 10px; align-items: center; }
  .kertas-pilih label:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); }
  .kertas-pilih b { display: block; }
  .kertas-pilih .lebar { height: 34px; background: repeating-linear-gradient(0deg, #fff, #fff 5px, #e6e9ee 5px, #e6e9ee 6px); border: 1px solid #b9c0ca; border-radius: 2px; flex: none; }
  @media (max-width: 1200px) { .resi-grid { grid-template-columns: 1fr; } .pratinjau { position: static; } }
</style>

<form method="post" id="f-resi">
  <?= csrf_field() ?>
  <div class="resi-grid">
    <div>
      <div class="card">
        <div class="card-h"><h2>Kertas & cetak</h2></div>
        <div class="card-b" style="display:grid;gap:14px">
          <div class="kertas-pilih">
            <label><input type="radio" name="kertas" value="58" <?= $r['kertas'] === 58 ? 'checked' : '' ?>><span class="lebar" style="width:30px"></span><span><b>56 / 58 mm</b><small class="muted">32 karakter per baris · printer portabel</small></span></label>
            <label><input type="radio" name="kertas" value="80" <?= $r['kertas'] === 80 ? 'checked' : '' ?>><span class="lebar" style="width:42px"></span><span><b>80 mm</b><small class="muted">48 karakter per baris · printer kasir</small></span></label>
          </div>
          <p class="muted" style="margin:0">Ini lebar bawaan untuk semua pos. Pos yang printernya berbeda bisa memilih sendiri di aplikasi petugas: <b>Shift → Printer → Lebar kertas</b>.</p>
          <label class="f" style="max-width:260px">Jarak sobek di akhir (baris kosong)<small>Tambah bila teks terakhir tertahan di mulut printer</small><input type="number" name="feed" min="1" max="10" value="<?= (int)$r['feed'] ?>"></label>
        </div>
      </div>

      <div class="card">
        <div class="card-h"><h2>Kepala resi</h2><span class="muted">Dipakai di tiket masuk & struk keluar</span></div>
        <div class="card-b form-grid">
          <label class="f">Nama lokasi<input name="nama_lokasi" required maxlength="40" value="<?= e(setting('nama_lokasi')) ?>"></label>
          <label class="f">Alamat<input name="alamat_lokasi" maxlength="80" value="<?= e(setting('alamat_lokasi')) ?>"></label>
          <label class="f">Telepon<input name="telepon_lokasi" maxlength="32" value="<?= e(setting('telepon_lokasi')) ?>"></label>
          <label class="f">Baris tambahan<small>Mis. nama pengelola, NPWP, situs. Satu per baris.</small><textarea name="header_tambahan" rows="2" maxlength="120"><?= e($r['header_tambahan']) ?></textarea></label>
        </div>
      </div>

      <div class="card">
        <div class="card-h"><h2>Tiket masuk</h2><button type="button" class="btn sm ghost" data-lihat="masuk">Lihat pratinjau</button></div>
        <div class="card-b" style="display:grid;gap:14px">
          <div class="form-grid">
            <label class="f">Judul tiket<input name="masuk[judul]" maxlength="40" value="<?= e($r['masuk']['judul']) ?>"></label>
            <label class="f">Teks di bawah QR<input name="masuk[teks_qr]" maxlength="80" value="<?= e($r['masuk']['teks_qr']) ?>"></label>
          </div>
          <div class="cek-grid">
            <?= cek('masuk[jenis_di_judul]', $r['masuk']['jenis_di_judul'], 'Jenis kendaraan di judul', '"TIKET PARKIR MOTOR"') ?>
            <?= cek('masuk[no_tiket]', $r['masuk']['no_tiket'], 'Nomor tiket') ?>
            <?= cek('masuk[plat_besar]', $r['masuk']['plat_besar'], 'Plat ukuran besar berbingkai') ?>
            <?= cek('masuk[gerbang]', $r['masuk']['gerbang'], 'Gerbang masuk') ?>
            <?= cek('masuk[petugas]', $r['masuk']['petugas'], 'Nama petugas') ?>
            <?= cek('masuk[no_bawah_qr]', $r['masuk']['no_bawah_qr'], 'Nomor tiket di bawah QR', 'Untuk diketik bila QR rusak') ?>
            <?= cek('masuk[tarif]', $r['masuk']['tarif'], 'Info tarif', 'Diambil dari menu Tarif') ?>
            <?= cek('masuk[denda]', $r['masuk']['denda'], 'Denda tiket hilang', 'Nominal dari menu Pengaturan') ?>
            <?= cek('masuk[ketentuan]', $r['masuk']['ketentuan'], 'Daftar ketentuan') ?>
          </div>
          <div class="form-grid">
            <label class="f">Judul ketentuan<input name="masuk[judul_ketentuan]" maxlength="40" value="<?= e($r['masuk']['judul_ketentuan']) ?>"></label>
          </div>
          <label class="f">Ketentuan<small>Satu ketentuan per baris. Baris denda tiket hilang ditambahkan otomatis bila dicentang di atas.</small><textarea name="footer_resi" rows="4" maxlength="600"><?= e(setting('footer_resi')) ?></textarea></label>
        </div>
      </div>

      <div class="card">
        <div class="card-h"><h2>Struk keluar</h2><button type="button" class="btn sm ghost" data-lihat="keluar">Lihat pratinjau</button></div>
        <div class="card-b" style="display:grid;gap:14px">
          <div class="form-grid">
            <label class="f">Judul struk<input name="keluar[judul]" maxlength="40" value="<?= e($r['keluar']['judul']) ?>"></label>
            <label class="f">Ucapan penutup<input name="footer_struk" maxlength="64" value="<?= e(setting('footer_struk', 'Terima kasih atas kunjungan Anda')) ?>"></label>
            <label class="f">Catatan paling bawah<input name="keluar[catatan]" maxlength="80" value="<?= e($r['keluar']['catatan']) ?>"></label>
          </div>
          <div class="cek-grid">
            <?= cek('keluar[no_struk]', $r['keluar']['no_struk'], 'Nomor struk') ?>
            <?= cek('keluar[no_tiket]', $r['keluar']['no_tiket'], 'Nomor tiket') ?>
            <?= cek('keluar[plat_besar]', $r['keluar']['plat_besar'], 'Plat ukuran besar berbingkai') ?>
            <?= cek('keluar[jenis]', $r['keluar']['jenis'], 'Jenis kendaraan') ?>
            <?= cek('keluar[durasi]', $r['keluar']['durasi'], 'Durasi parkir') ?>
            <?= cek('keluar[rincian]', $r['keluar']['rincian'], 'Rincian biaya', 'Per hari, menginap, denda') ?>
            <?= cek('keluar[tunai]', $r['keluar']['tunai'], 'Uang tunai & kembalian') ?>
            <?= cek('keluar[gerbang]', $r['keluar']['gerbang'], 'Gerbang keluar') ?>
            <?= cek('keluar[petugas]', $r['keluar']['petugas'], 'Nama petugas') ?>
            <?= cek('keluar[waktu_cetak]', $r['keluar']['waktu_cetak'], 'Waktu cetak') ?>
          </div>
          <p class="muted" style="margin:0">Waktu masuk, waktu keluar, total, dan metode pembayaran selalu dicetak.</p>
        </div>
      </div>

      <div class="form-foot" style="margin-bottom:24px">
        <button class="btn">Simpan tampilan resi</button>
        <button class="btn ghost" name="aksi" value="reset" formnovalidate data-confirm-btn="Kembalikan semua pilihan tampilan resi ke bawaan?">Kembalikan bawaan</button>
      </div>
    </div>

    <aside class="pratinjau">
      <div class="card" style="margin:0">
        <div class="card-h" style="gap:8px">
          <div class="seg-admin" id="seg-jenis"><button type="button" data-v="masuk" class="on">Tiket masuk</button><button type="button" data-v="keluar">Struk keluar</button></div>
          <div class="seg-admin" id="seg-lebar"><button type="button" data-v="58">56/58</button><button type="button" data-v="80">80 mm</button></div>
          <div class="seg-admin" id="seg-tampil"><button type="button" data-v="layar" class="on">Layar</button><button type="button" data-v="cetak" title="Gambar persis seperti yang dikirim ke printer (mode cetak sebagai gambar)">Hasil cetak</button></div>
        </div>
        <div class="kertas-bg" id="pratinjau"></div>
        <div class="card-b muted" style="font-size:12.5px;padding:10px 14px">Contoh data. Perubahan langsung terlihat; klik <b>Simpan</b> untuk menerapkan ke semua pos.</div>
      </div>
    </aside>
  </div>
</form>

<script src="<?= e(base_url('pwa/vendor/qrcode.js')) ?>"></script>
<script src="<?= e(base_url('pwa/js/tarif.js')) ?>"></script>
<script src="<?= e(base_url('pwa/js/resi.js')) ?>?v=7"></script>
<script>
(function () {
  var dasar = <?= json_encode($cfgDasar, JSON_UNESCAPED_UNICODE) ?>;
  var f = document.getElementById('f-resi'), box = document.getElementById('pratinjau');
  var st = { jenis: 'masuk', lebar: null, tampil: 'layar' };

  function nilai(n) { var el = f.elements[n]; return el ? el.value : ''; }
  function centang(n) { var el = f.elements[n]; return !!(el && el.checked); }

  function cfgDariForm() {
    var resi = { kertas: +(f.querySelector('input[name=kertas]:checked') || {}).value || 58, feed: +nilai('feed') || 4, header_tambahan: nilai('header_tambahan'), masuk: {}, keluar: {} };
    ['masuk', 'keluar'].forEach(function (j) {
      Object.keys(Resi.BAWAAN[j]).forEach(function (k) {
        resi[j][k] = typeof Resi.BAWAAN[j][k] === 'boolean' ? centang(j + '[' + k + ']') : nilai(j + '[' + k + ']');
      });
    });
    return Object.assign({}, dasar, {
      nama_lokasi: nilai('nama_lokasi'), alamat_lokasi: nilai('alamat_lokasi'), telepon_lokasi: nilai('telepon_lokasi'),
      footer_resi: nilai('footer_resi'), footer_struk: nilai('footer_struk'), resi: resi
    });
  }

  // Contoh data: masuk 2 jam 35 menit lalu, tarif motor yang berlaku
  var keluarT = new Date(), masukT = new Date(keluarT.getTime() - (2 * 60 + 35) * 60000);
  var id = '3d8a7bc5-1234-4abc-9def-0123456789ab';
  var contohMasuk = { id: id, kode_qr: 'PK1.' + id + '.M.tn1k9w.cca9ffb5412345ab', plat: 'B 1234 XYZ', jenis: 'motor',
    waktu_masuk: Tarif.fmt(masukT), pos_masuk: 'Gerbang Utara', petugas: 'Budi Santoso' };
  function contohKeluar() {
    var t = Tarif.pilihTarif(dasar.tarif, 'motor', contohMasuk.waktu_masuk);
    var r = t ? Tarif.hitung(t, contohMasuk.waktu_masuk, Tarif.fmt(keluarT), null, 0) : { durasi_menit: 155, total: 4000 };
    return { no_struk: 'S' + Tarif.fmt(keluarT).slice(2, 10).replace(/-/g, '') + '-000123', no_tiket: Resi.noTiket(id), plat: contohMasuk.plat, jenis: 'motor',
      waktu_masuk: contohMasuk.waktu_masuk, waktu_keluar: Tarif.fmt(keluarT), durasi_menit: r.durasi_menit,
      rincian: t ? Tarif.rincianBaris(r) : [['2 j 35 m', 4000]], total: r.total, tunai: Math.ceil((r.total || 1) / 10000) * 10000 + 10000, metode: 'cash',
      pos_keluar: 'Gerbang Selatan', petugas: 'Siti Aminah' };
  }

  function render() {
    var cfg = cfgDariForm(), mm = st.lebar || cfg.resi.kertas;
    document.querySelectorAll('#seg-lebar button').forEach(function (b) { b.classList.toggle('on', +b.dataset.v === +mm); });
    var rows = st.jenis === 'masuk' ? Resi.masuk(contohMasuk, cfg) : Resi.keluar(contohKeluar(), cfg);
    if (st.tampil === 'cetak') {
      box.innerHTML = '';
      var c = Resi.canvas(rows, { mm: mm, qrTipis: 1 });
      c.style.width = (mm === 80 ? 432 : 288) + 'px'; // 0,75 px per titik printer
      box.appendChild(c);
    } else {
      box.innerHTML = Resi.html(rows, mm);
    }
  }

  function seg(idSeg, kunci) {
    document.getElementById(idSeg).addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      st[kunci] = kunci === 'lebar' ? +b.dataset.v : b.dataset.v;
      this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      render();
    });
  }
  seg('seg-jenis', 'jenis'); seg('seg-lebar', 'lebar'); seg('seg-tampil', 'tampil');
  document.querySelectorAll('[data-lihat]').forEach(function (b) {
    b.addEventListener('click', function () { document.querySelector('#seg-jenis button[data-v="' + b.dataset.lihat + '"]').click(); });
  });
  f.addEventListener('input', render);
  f.addEventListener('change', function (e) { if (e.target.name === 'kertas') st.lebar = null; render(); });
  f.querySelector('[data-confirm-btn]').addEventListener('click', function (e) { if (!confirm(this.getAttribute('data-confirm-btn'))) e.preventDefault(); });
  render();
})();
</script>
<?php layout_bawah();
