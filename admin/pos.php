<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

if (!kolom_tersedia_tabel('pos')) {
    layout_atas('Pos & Gerbang', 'pos.php');
    echo '<div class="alert err">Tabel pos belum ada. Jalankan migrasi database: <code>php cli/migrasi.php</code> atau impor <code>database/migrasi.sql</code>.</div>';
    layout_bawah();
    exit;
}

if (is_post()) {
    cek_csrf();
    $aksi = $_POST['aksi'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($aksi === 'simpan') {
        $nama = trim(preg_replace('/\s+/', ' ', (string)($_POST['nama'] ?? '')));
        $jenis = ($_POST['jenis'] ?? '') === 'keluar' ? 'keluar' : 'masuk';
        $ket = mb_substr(trim((string)($_POST['keterangan'] ?? '')), 0, 120) ?: null;
        $urutan = (int)($_POST['urutan'] ?? 0);
        $petugas = array_values(array_unique(array_map('intval', (array)($_POST['petugas'] ?? []))));
        if ($nama === '' || mb_strlen($nama) > 50) {
            flash('Nama pos wajib diisi (maks 50 karakter).', 'err');
        } elseif (q_val('SELECT 1 FROM pos WHERE LOWER(nama) = LOWER(?) AND id <> ?', [$nama, $id])) {
            flash("Nama pos \"$nama\" sudah dipakai.", 'err');
        } else {
            $sebelum = $id ? q_one('SELECT * FROM pos WHERE id = ?', [$id]) : null;
            if ($id) {
                q('UPDATE pos SET nama = ?, jenis = ?, keterangan = ?, urutan = ? WHERE id = ?', [$nama, $jenis, $ket, $urutan, $id]);
            } else {
                q('INSERT INTO pos (nama, jenis, keterangan, urutan, dibuat) VALUES (?,?,?,?,?)', [$nama, $jenis, $ket, $urutan, now()]);
                $id = (int)db()->lastInsertId();
            }
            q('DELETE FROM pos_petugas WHERE pos_id = ?', [$id]);
            foreach ($petugas as $pid) {
                if (q_val('SELECT 1 FROM petugas WHERE id = ?', [$pid])) q('INSERT INTO pos_petugas (pos_id, petugas_id) VALUES (?, ?)', [$id, $pid]);
            }
            log_aktivitas(admin_id(), $sebelum ? 'ubah_pos' : 'tambah_pos', $id, $sebelum, compact('nama', 'jenis', 'ket', 'petugas'));
            flash($sebelum ? "Pos \"$nama\" diperbarui." : "Pos \"$nama\" ditambahkan.");
            redirect('pos.php');
        }
    } elseif ($aksi === 'toggle' && $id) {
        $r = q_one('SELECT * FROM pos WHERE id = ?', [$id]);
        if ($r) {
            q('UPDATE pos SET aktif = 1 - aktif WHERE id = ?', [$id]);
            log_aktivitas(admin_id(), 'ubah_pos', $id, ['aktif' => (int)$r['aktif']], ['aktif' => 1 - (int)$r['aktif']]);
            flash('Pos "' . $r['nama'] . '" ' . ($r['aktif'] ? 'dinonaktifkan. Shift yang sedang berjalan di pos ini tidak terpengaruh.' : 'diaktifkan kembali.'));
        }
    }
    redirect(self_url());
}

$edit = !empty($_GET['edit']) ? q_one('SELECT * FROM pos WHERE id = ?', [(int)$_GET['edit']]) : null;
$editPetugas = $edit ? array_map('intval', array_column(q_all('SELECT petugas_id FROM pos_petugas WHERE pos_id = ?', [$edit['id']]), 'petugas_id')) : [];
$daftar = q_all('SELECT * FROM pos ORDER BY aktif DESC, jenis, urutan, nama');
$semuaPetugas = q_all("SELECT id, nama, username, role FROM petugas WHERE aktif = 1 ORDER BY role = 'admin', nama");
$saran = array_column(q_all("SELECT DISTINCT pos_nama FROM shift WHERE pos_nama <> '' AND pos_nama NOT IN (SELECT nama FROM pos) ORDER BY pos_nama LIMIT 30"), 'pos_nama');

layout_atas('Pos & Gerbang', 'pos.php');
?>
<?php
// Setelah ada pos, isian nama pos bebas di aplikasi dimatikan: pastikan pos masuk & pos keluar sama-sama tersedia.
$aktifPerJenis = ['masuk' => 0, 'keluar' => 0];
foreach ($daftar as $p) if ($p['aktif']) $aktifPerJenis[$p['jenis']]++;
if ($daftar && (!$aktifPerJenis['masuk'] || !$aktifPerJenis['keluar'])): ?>
<div class="alert err">Belum ada <b>pos <?= !$aktifPerJenis['masuk'] ? 'masuk' : 'keluar' ?></b> yang aktif. Karena daftar pos sudah dipakai, petugas tidak bisa lagi mengetik nama pos sendiri, sehingga tidak ada yang bisa membuka shift pos <?= !$aktifPerJenis['masuk'] ? 'masuk' : 'keluar' ?>. Tambahkan pos tersebut.</div>
<?php endif; ?>
<?php if (!$daftar): ?>
<div class="alert warn">Belum ada pos. Selama daftar ini kosong, petugas mengetik nama pos sendiri saat membuka shift. Setelah pos pertama ditambahkan, petugas cukup memilih dari daftar.</div>
<?php endif; ?>
<div class="grid" style="grid-template-columns:minmax(0,1fr) minmax(0,2fr)">
  <form class="card" method="post">
    <div class="card-h"><h2><?= $edit ? 'Ubah pos' : 'Tambah pos' ?></h2></div>
    <div class="card-b" style="display:grid;gap:12px">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <label class="f">Nama pos / gerbang<input name="nama" required maxlength="50" list="saran-pos" value="<?= e($edit['nama'] ?? '') ?>" placeholder="mis. Gerbang Utara"></label>
      <datalist id="saran-pos"><?php foreach ($saran as $sn): ?><option value="<?= e($sn) ?>"><?php endforeach; ?></datalist>
      <div class="kertas-pilih jenis-pos">
        <label><input type="radio" name="jenis" value="masuk" <?= ($edit['jenis'] ?? 'masuk') === 'masuk' ? 'checked' : '' ?>><span><b>⬇ Pos masuk</b><small class="muted">Foto plat, cetak tiket</small></span></label>
        <label><input type="radio" name="jenis" value="keluar" <?= ($edit['jenis'] ?? '') === 'keluar' ? 'checked' : '' ?>><span><b>⬆ Pos keluar</b><small class="muted">Scan tiket, terima bayar</small></span></label>
      </div>
      <label class="f">Keterangan<small>Opsional, tampil di aplikasi petugas</small><input name="keterangan" maxlength="120" value="<?= e($edit['keterangan'] ?? '') ?>" placeholder="mis. Lajur motor, sisi Jl. Merdeka"></label>
      <label class="f" style="max-width:140px">Urutan tampil<input type="number" name="urutan" value="<?= (int)($edit['urutan'] ?? 0) ?>"></label>
      <div class="f">Petugas yang ditugaskan
        <small>Petugas yang dicentang hanya melihat pos tugasnya saat membuka shift. Petugas yang belum ditugaskan ke pos mana pun melihat semua pos aktif.</small>
        <div class="daftar-cek">
          <?php foreach ($semuaPetugas as $pt): ?>
          <label><input type="checkbox" name="petugas[]" value="<?= (int)$pt['id'] ?>" <?= in_array((int)$pt['id'], $editPetugas, true) ? 'checked' : '' ?>> <?= e($pt['nama']) ?> <small class="muted"><?= e($pt['username']) ?><?= $pt['role'] === 'admin' ? ' · admin' : '' ?></small></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-foot"><button class="btn">Simpan pos</button><?php if ($edit): ?><a class="btn ghost" href="pos.php">Batal</a><?php endif; ?></div>
    </div>
  </form>

  <div class="card">
    <div class="card-h"><h2>Daftar pos</h2><span class="muted"><?= count(array_filter($daftar, function ($p) { return $p['aktif']; })) ?> aktif</span></div>
    <div class="card-b flush table-wrap">
      <table>
        <thead><tr><th>Pos</th><th>Jenis</th><th>Petugas ditugaskan</th><th>Shift berjalan</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($daftar as $p):
            $tugas = array_column(q_all('SELECT pt.nama FROM pos_petugas pp JOIN petugas pt ON pt.id = pp.petugas_id WHERE pp.pos_id = ? ORDER BY pt.nama', [$p['id']]), 'nama');
            $jalan = array_column(q_all('SELECT pt.nama FROM shift s JOIN petugas pt ON pt.id = s.petugas_id WHERE s.pos_id = ? AND s.waktu_tutup IS NULL', [$p['id']]), 'nama'); ?>
          <tr>
            <td><b><?= e($p['nama']) ?></b><?php if ($p['keterangan']): ?><br><small class="muted"><?= e($p['keterangan']) ?></small><?php endif; ?></td>
            <td><?= badge($p['jenis'], $p['jenis'] === 'masuk' ? 'info' : 'ok') ?></td>
            <td><?= $tugas ? e(implode(', ', $tugas)) : '<span class="muted">Petugas tanpa penugasan khusus</span>' ?></td>
            <td><?= $jalan ? badge(implode(', ', $jalan), 'info') : '<span class="muted">–</span>' ?></td>
            <td><?= $p['aktif'] ? badge('aktif', 'ok') : badge('nonaktif', 'danger') ?></td>
            <td class="actions"><a class="btn sm ghost" href="?edit=<?= (int)$p['id'] ?>">Ubah</a>
              <form method="post" data-confirm="<?= $p['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?> pos <?= e($p['nama']) ?>?"><?= csrf_field() ?><input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn sm ghost"><?= $p['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form></td>
          </tr>
        <?php endforeach; if (!$daftar): ?><tr><td colspan="6" class="empty">Belum ada pos.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php layout_bawah();
