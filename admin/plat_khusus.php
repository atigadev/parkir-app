<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

if (is_post()) {
    cek_csrf();
    $aksi = $_POST['aksi'] ?? '';
    if ($aksi === 'simpan') {
        $plat = normalisasi_plat((string)($_POST['plat_nomor'] ?? ''));
        $tipe = in_array($_POST['tipe'] ?? '', ['member', 'dinas', 'gratis'], true) ? $_POST['tipe'] : 'member';
        $sampai = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['berlaku_sampai'] ?? '')) ? $_POST['berlaku_sampai'] : null;
        $ket = mb_substr(trim((string)($_POST['keterangan'] ?? '')), 0, 100) ?: null;
        if (strlen(str_replace(' ', '', $plat)) < 3) {
            flash('Plat tidak valid.', 'err');
        } elseif (!empty($_POST['id'])) {
            $lama = q_one('SELECT * FROM plat_khusus WHERE id = ?', [(int)$_POST['id']]);
            q('UPDATE plat_khusus SET plat_nomor = ?, tipe = ?, keterangan = ?, berlaku_sampai = ? WHERE id = ?', [$plat, $tipe, $ket, $sampai, (int)$_POST['id']]);
            log_aktivitas(admin_id(), 'ubah_plat_khusus', (int)$_POST['id'], $lama, compact('plat', 'tipe', 'ket', 'sampai'));
            flash('Plat khusus diperbarui.');
        } else {
            q('INSERT INTO plat_khusus (plat_nomor, tipe, keterangan, berlaku_sampai) VALUES (?,?,?,?)', [$plat, $tipe, $ket, $sampai]);
            log_aktivitas(admin_id(), 'tambah_plat_khusus', db()->lastInsertId(), null, compact('plat', 'tipe', 'ket', 'sampai'));
            flash("Plat $plat ditambahkan sebagai $tipe.");
        }
    } elseif ($aksi === 'toggle') {
        $r = q_one('SELECT * FROM plat_khusus WHERE id = ?', [(int)$_POST['id']]);
        if ($r) {
            q('UPDATE plat_khusus SET aktif = 1 - aktif WHERE id = ?', [$r['id']]);
            log_aktivitas(admin_id(), 'ubah_plat_khusus', $r['id'], ['aktif' => (int)$r['aktif']], ['aktif' => 1 - (int)$r['aktif']]);
        }
    }
    redirect('plat_khusus.php');
}

$edit = !empty($_GET['edit']) ? q_one('SELECT * FROM plat_khusus WHERE id = ?', [(int)$_GET['edit']]) : null;
$rows = q_all('SELECT * FROM plat_khusus ORDER BY aktif DESC, plat_nomor');

layout_atas('Plat Khusus', 'plat_khusus.php');
?>
<div class="grid" style="grid-template-columns:minmax(0,1fr) minmax(0,2fr)">
  <form class="card" method="post">
    <div class="card-h"><h2><?= $edit ? 'Ubah plat khusus' : 'Tambah plat khusus' ?></h2></div>
    <div class="card-b" style="display:grid;gap:12px">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?: '' ?>">
      <label class="f">Plat nomor<input name="plat_nomor" required value="<?= e($edit['plat_nomor'] ?? '') ?>" placeholder="B 1234 ABC" style="text-transform:uppercase"></label>
      <label class="f">Tipe<select name="tipe">
        <?php foreach (['member' => 'Member', 'dinas' => 'Kendaraan dinas', 'gratis' => 'Gratis'] as $v => $l): ?><option value="<?= $v ?>" <?= ($edit['tipe'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select></label>
      <label class="f">Keterangan<input name="keterangan" value="<?= e($edit['keterangan'] ?? '') ?>" placeholder="Nama pemilik / instansi"></label>
      <label class="f">Berlaku sampai<small>Kosongkan bila tanpa batas</small><input type="date" name="berlaku_sampai" value="<?= e($edit['berlaku_sampai'] ?? '') ?>"></label>
      <div class="form-foot"><button class="btn">Simpan</button><?php if ($edit): ?><a class="btn ghost" href="plat_khusus.php">Batal</a><?php endif; ?></div>
      <p class="muted" style="margin:0">Plat khusus yang masih berlaku dikenakan biaya Rp0 saat keluar dan tercatat tipenya di rincian.</p>
    </div>
  </form>
  <div class="card">
    <div class="card-h"><h2>Daftar plat khusus</h2><span class="muted"><?= count($rows) ?> plat</span></div>
    <div class="card-b flush table-wrap">
      <table>
        <thead><tr><th>Plat</th><th>Tipe</th><th>Keterangan</th><th>Berlaku sampai</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $kadaluarsa = $r['berlaku_sampai'] && $r['berlaku_sampai'] < date('Y-m-d'); ?>
          <tr>
            <td><span class="plat"><?= e($r['plat_nomor']) ?></span></td><td><?= badge($r['tipe'], 'info') ?></td><td><?= e($r['keterangan']) ?></td>
            <td><?= $r['berlaku_sampai'] ? tgl($r['berlaku_sampai'], 'd/m/Y') : '<span class="muted">Tanpa batas</span>' ?></td>
            <td><?= !$r['aktif'] ? badge('nonaktif', 'danger') : ($kadaluarsa ? badge('kedaluwarsa', 'warn') : badge('aktif', 'ok')) ?></td>
            <td class="actions"><a class="btn sm ghost" href="?edit=<?= (int)$r['id'] ?>">Ubah</a>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm ghost"><?= $r['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form></td>
          </tr>
        <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="empty">Belum ada plat khusus.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php layout_bawah();
