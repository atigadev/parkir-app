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

// Tindak lanjut selisih setoran (setoran susulan, ditanggung petugas, dihapuskan, dikembalikan)
if (is_post() && ($_POST['aksi'] ?? '') === 'tindak_lanjut') {
    cek_csrf();
    try {
        $h = tindak_lanjut_selisih((int)$_POST['id'], (string)($_POST['jenis'] ?? ''), (int)($_POST['nominal'] ?? 0), (string)($_POST['catatan'] ?? ''), admin_id());
        flash($h['status'] === 'sebagian' ? 'Setoran susulan dicatat. Sisa kekurangan ' . rupiah(-$h['sisa']) . '.' : 'Tindak lanjut selisih dicatat.');
    } catch (ParkirException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect(self_url());
}

$dari = (string)($_GET['dari'] ?? date('Y-m-d', strtotime('-6 days')));
$sampai = (string)($_GET['sampai'] ?? date('Y-m-d'));
$terbuka = ($_GET['selisih'] ?? '') === 'terbuka';
$adaTL = kolom_tersedia('shift', 'selisih_status');
if ($terbuka && $adaTL) {
    // Semua selisih yang belum tuntas, tanpa batas tanggal
    $rows = q_all("SELECT s.*, p.nama FROM shift s JOIN petugas p ON p.id = s.petugas_id
        WHERE s.pos = 'keluar' AND s.waktu_tutup IS NOT NULL
          AND ((s.selisih + s.setor_susulan < 0 AND (s.selisih_status IS NULL OR s.selisih_status = 'sebagian'))
            OR (s.selisih > 0 AND s.selisih_status IS NULL))
        ORDER BY s.waktu_tutup DESC LIMIT 300");
} else {
    $rows = q_all("SELECT s.*, p.nama FROM shift s JOIN petugas p ON p.id = s.petugas_id
        WHERE s.waktu_tutup IS NULL OR (s.waktu_buka >= ? AND s.waktu_buka < ? + INTERVAL 1 DAY)
        ORDER BY s.waktu_tutup IS NULL DESC, s.waktu_buka DESC LIMIT 300", [$dari, $sampai]);
}

layout_atas('Shift & Setoran', 'shift.php');
?>
<div class="card">
  <div class="card-h">
    <form class="filters" method="get">
      <label class="f">Dari<input type="date" name="dari" value="<?= e($dari) ?>"></label>
      <label class="f">Sampai<input type="date" name="sampai" value="<?= e($sampai) ?>"></label>
      <button class="btn">Tampilkan</button>
    </form>
    <div class="actions">
      <?php if ($adaTL): ?><a class="btn sm <?= $terbuka ? '' : 'ghost' ?>" href="?selisih=terbuka">Selisih belum tuntas</a><?php endif; ?>
      <?php if ($terbuka): ?><a class="btn sm ghost" href="shift.php">Semua shift</a><?php endif; ?>
    </div>
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
          <td class="num"><?php if (!$buka) echo $adaTL ? badge_selisih($s) : ((int)$s['selisih'] === 0 ? badge('pas', 'ok') : badge(rupiah($s['selisih']), (int)$s['selisih'] < 0 ? 'danger' : 'warn')); ?></td>
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
            <?php elseif ($adaTL && !$posMasuk && (int)$s['selisih'] !== 0 && (!$s['selisih_status'] || $s['selisih_status'] === 'sebagian')): $sisa = sisa_selisih($s); ?>
            <button type="button" class="btn sm <?= $sisa < 0 ? 'danger' : 'ghost' ?>" data-tindak-lanjut="<?= e(json_encode([
                'id' => (int)$s['id'], 'petugas' => $s['nama'], 'pos' => $s['pos_nama'], 'tutup' => tgl($s['waktu_tutup'], 'd/m/Y H:i'),
                'sistem' => (int)$s['total_cash_sistem'], 'setor' => (int)$s['total_cash_setor'], 'selisih' => (int)$s['selisih'],
                'susulan' => (int)($s['setor_susulan'] ?? 0), 'sisa' => $sisa,
            ])) ?>">Tindak lanjut</button>
            <?php elseif (!empty($s['selisih_catatan'])): ?><span class="muted" title="<?= e($s['selisih_catatan']) ?>">📝 tindak lanjut</span>
            <?php elseif ($s['catatan']): ?><span class="muted" title="<?= e($s['catatan']) ?>">📝</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; if (!$rows): ?><tr><td colspan="11" class="empty">Tidak ada shift.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($adaTL): ?>
<dialog class="modal" id="modal-tl" aria-labelledby="mtl-judul">
  <form method="post" class="modal-isi" id="form-tl">
    <?= csrf_field() ?><input type="hidden" name="aksi" value="tindak_lanjut"><input type="hidden" name="id" id="mtl-id">
    <header class="modal-kepala">
      <div><h2 id="mtl-judul">Tindak lanjut selisih setoran</h2><small class="muted" id="mtl-sub"></small></div>
      <button type="button" class="icon-btn" data-tutup-modal aria-label="Tutup">✕</button>
    </header>

    <div class="modal-badan">
      <div class="mtl-ringkas">
        <div><small>Cash sistem</small><b id="mtl-sistem"></b></div>
        <div><small>Disetor</small><b id="mtl-setor"></b></div>
        <div id="mtl-blok-susulan"><small>Susulan sebelumnya</small><b id="mtl-susulan"></b></div>
        <div class="mtl-sisa"><small id="mtl-sisa-label">Sisa kurang setor</small><b id="mtl-sisa"></b></div>
      </div>

      <fieldset class="mtl-opsi">
        <legend>Tindak lanjut</legend>
        <?php
        $penjelasan = [
            'susulan'      => 'Petugas menyetorkan kekurangan. Boleh sebagian; sisanya tetap tercatat sampai lunas.',
            'ditanggung'   => 'Kekurangan menjadi tanggungan petugas, mis. dipotong dari gaji atau dicicil di luar sistem.',
            'dihapuskan'   => 'Pengelola mengikhlaskan kekurangan ini. Gunakan bila ada alasan yang jelas.',
            'dikembalikan' => 'Kelebihan setor dikembalikan ke petugas atau dicatat sebagai masuk kas.',
        ];
        foreach (TINDAK_LANJUT_SELISIH as $k => $l): ?>
        <label class="opsi-kartu" data-untuk="<?= $k === 'dikembalikan' ? 'lebih' : 'kurang' ?>">
          <input type="radio" name="jenis" value="<?= e($k) ?>" required>
          <span><b><?= e($l) ?></b><small><?= e($penjelasan[$k]) ?></small></span>
        </label>
        <?php endforeach; ?>
      </fieldset>

      <div id="mtl-blok-nominal" class="mtl-nominal">
        <label class="f">Nominal setoran susulan (Rp)
          <div class="mtl-nominal-baris">
            <input type="number" name="nominal" id="mtl-nominal" min="500" step="500" inputmode="numeric">
            <button type="button" class="btn ghost" id="mtl-lunasi">Lunasi</button>
          </div>
        </label>
        <small class="muted" id="mtl-pratinjau"></small>
      </div>

      <label class="f">Catatan <small>Wajib. Tulis kapan, ke siapa, dan saksinya bila ada.</small>
        <textarea name="catatan" id="mtl-catatan" rows="2" required maxlength="150" placeholder="mis. disetor tunai ke kasir 18/10 pukul 08.00, saksi Pak Budi"></textarea>
      </label>
    </div>

    <footer class="modal-kaki">
      <button type="button" class="btn ghost" data-tutup-modal>Batal</button>
      <button class="btn" id="mtl-simpan">Simpan tindak lanjut</button>
    </footer>
  </form>
</dialog>
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  var dlg = document.getElementById('modal-tl'), f = document.getElementById('form-tl');
  if (!dlg || !dlg.showModal) return;
  var rp = function (n) { return 'Rp' + Number(n || 0).toLocaleString('id-ID'); };
  var $ = function (id) { return document.getElementById(id); };
  var data = null;

  function perbarui() {
    var jenis = (f.querySelector('input[name=jenis]:checked') || {}).value;
    var susulan = jenis === 'susulan';
    $('mtl-blok-nominal').hidden = !susulan;
    $('mtl-nominal').required = susulan;
    if (susulan) {
      var n = +$('mtl-nominal').value || 0, sisa = -data.sisa - n;
      $('mtl-pratinjau').textContent = n > -data.sisa ? 'Nominal melebihi sisa kekurangan ' + rp(-data.sisa) + '.'
        : sisa === 0 ? 'Setelah disimpan: LUNAS.' : 'Setelah disimpan masih kurang ' + rp(sisa) + '.';
      $('mtl-pratinjau').className = n > -data.sisa ? 'teks-bahaya' : 'muted';
    }
    $('mtl-simpan').textContent = jenis === 'susulan' ? 'Simpan setoran susulan' : 'Simpan tindak lanjut';
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-tindak-lanjut]');
    if (!b) return;
    data = JSON.parse(b.getAttribute('data-tindak-lanjut'));
    var kurang = data.sisa < 0;
    f.reset();
    $('mtl-id').value = data.id;
    $('mtl-sub').textContent = data.petugas + ' · ' + data.pos + ' · ditutup ' + data.tutup;
    $('mtl-sistem').textContent = rp(data.sistem);
    $('mtl-setor').textContent = rp(data.setor);
    $('mtl-blok-susulan').hidden = !data.susulan;
    $('mtl-susulan').textContent = rp(data.susulan);
    $('mtl-sisa-label').textContent = kurang ? 'Sisa kurang setor' : 'Lebih setor';
    $('mtl-sisa').textContent = rp(Math.abs(data.sisa));
    $('mtl-sisa').parentNode.className = 'mtl-sisa ' + (kurang ? 'kurang' : 'lebih');
    f.querySelectorAll('.opsi-kartu').forEach(function (k) {
      var tampil = k.getAttribute('data-untuk') === (kurang ? 'kurang' : 'lebih');
      k.hidden = !tampil;
      k.querySelector('input').disabled = !tampil;
    });
    f.querySelector('.opsi-kartu:not([hidden]) input').checked = true;
    $('mtl-nominal').max = Math.abs(data.sisa);
    $('mtl-nominal').value = kurang ? -data.sisa : '';
    perbarui();
    dlg.showModal();
    $('mtl-catatan').focus();
  });
  f.addEventListener('change', perbarui);
  $('mtl-nominal').addEventListener('input', perbarui);
  $('mtl-lunasi').addEventListener('click', function () { $('mtl-nominal').value = -data.sisa; perbarui(); });
  dlg.querySelectorAll('[data-tutup-modal]').forEach(function (b) { b.addEventListener('click', function () { dlg.close(); }); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  f.addEventListener('submit', function (e) {
    if ((f.querySelector('input[name=jenis]:checked') || {}).value === 'susulan' && +$('mtl-nominal').value > -data.sisa) { e.preventDefault(); $('mtl-nominal').focus(); }
  });
})();
</script>
<?php endif; ?>
<?php layout_bawah();
