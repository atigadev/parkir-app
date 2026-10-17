<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

if (is_post()) {
    cek_csrf();
    $aksi = $_POST['aksi'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($aksi === 'simpan') {
        $nama = trim((string)($_POST['nama'] ?? ''));
        $user = strtolower(trim((string)($_POST['username'] ?? '')));
        $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'petugas';
        $pass = (string)($_POST['password'] ?? '');
        if ($nama === '' || !preg_match('/^[a-z0-9._]{3,50}$/', $user)) {
            flash('Nama wajib diisi; username 3–50 karakter huruf kecil, angka, titik, atau garis bawah.', 'err');
        } elseif (q_val('SELECT 1 FROM petugas WHERE username = ? AND id <> ?', [$user, $id])) {
            flash("Username $user sudah dipakai.", 'err');
        } elseif (!$id && strlen($pass) < 6) {
            flash('Password minimal 6 karakter.', 'err');
        } elseif ($id === admin_id() && $role !== 'admin') {
            flash('Anda tidak dapat menurunkan role akun sendiri.', 'err');
        } else {
            if ($id) {
                $lama = q_one('SELECT nama, username, role FROM petugas WHERE id = ?', [$id]);
                q('UPDATE petugas SET nama = ?, username = ?, role = ? WHERE id = ?', [$nama, $user, $role, $id]);
                log_aktivitas(admin_id(), 'ubah_petugas', $id, $lama, compact('nama', 'user', 'role'));
                flash('Data petugas diperbarui.');
            } else {
                q('INSERT INTO petugas (nama, username, password_hash, role) VALUES (?,?,?,?)', [$nama, $user, password_hash($pass, PASSWORD_DEFAULT), $role]);
                log_aktivitas(admin_id(), 'tambah_petugas', db()->lastInsertId(), null, compact('nama', 'user', 'role'));
                flash("Akun $user dibuat.");
            }
            redirect('petugas.php');
        }
    } elseif ($aksi === 'reset' && $id) {
        $pass = (string)($_POST['password'] ?? '');
        if (strlen($pass) < 6) {
            flash('Password minimal 6 karakter.', 'err');
        } else {
            q('UPDATE petugas SET password_hash = ?, api_token_hash = NULL, token_expires = NULL WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
            log_aktivitas(admin_id(), 'reset_password', $id);
            flash('Password direset. Petugas perlu login ulang di aplikasi.');
        }
    } elseif ($aksi === 'toggle' && $id && $id !== admin_id()) {
        q('UPDATE petugas SET aktif = 1 - aktif, api_token_hash = NULL, token_expires = NULL WHERE id = ?', [$id]);
        log_aktivitas(admin_id(), 'ubah_status_petugas', $id);
        flash('Status akun diubah.');
    }
    redirect(self_url());
}

$edit = !empty($_GET['edit']) ? q_one('SELECT * FROM petugas WHERE id = ?', [(int)$_GET['edit']]) : null;
$rows = q_all('SELECT p.*, (SELECT CONCAT(s.pos_nama, "|", s.waktu_buka) FROM shift s WHERE s.petugas_id = p.id AND s.waktu_tutup IS NULL LIMIT 1) shift_buka FROM petugas p ORDER BY p.aktif DESC, p.role, p.nama');

layout_atas('Petugas', 'petugas.php');
?>
<div class="grid" style="grid-template-columns:minmax(0,1fr) minmax(0,2fr)">
  <form class="card" method="post">
    <div class="card-h"><h2><?= $edit ? 'Ubah akun' : 'Tambah akun' ?></h2></div>
    <div class="card-b" style="display:grid;gap:12px">
      <?= csrf_field() ?><input type="hidden" name="aksi" value="simpan"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <label class="f">Nama lengkap<input name="nama" required value="<?= e($edit['nama'] ?? '') ?>"></label>
      <label class="f">Username<input name="username" required pattern="[a-z0-9._]{3,50}" value="<?= e($edit['username'] ?? '') ?>" autocomplete="off"></label>
      <label class="f">Role<select name="role"><option value="petugas">Petugas pos</option><option value="admin" <?= ($edit['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option></select></label>
      <?php if (!$edit): ?><label class="f">Password awal<input name="password" type="password" minlength="6" required autocomplete="new-password"></label><?php endif; ?>
      <div class="form-foot"><button class="btn">Simpan</button><?php if ($edit): ?><a class="btn ghost" href="petugas.php">Batal</a><?php endif; ?></div>
    </div>
    <?php if ($edit): ?>
    </form>
    <form class="card" method="post" style="margin-top:-1px">
      <div class="card-b" style="display:grid;gap:12px">
        <?= csrf_field() ?><input type="hidden" name="aksi" value="reset"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <label class="f">Reset password<input name="password" type="password" minlength="6" required autocomplete="new-password" placeholder="Password baru"></label>
        <div><button class="btn ghost">Reset password</button></div>
      </div>
    <?php endif; ?>
  </form>
  <div class="card">
    <div class="card-h"><h2>Daftar akun</h2></div>
    <div class="card-b flush table-wrap">
      <table>
        <thead><tr><th>Nama</th><th>Username</th><th>Role</th><th>Login terakhir</th><th>Versi aplikasi</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['nama']) ?><?php if ($r['shift_buka']): [$pn] = explode('|', $r['shift_buka']); ?><br><small class="muted">Shift berjalan · <?= e($pn) ?></small><?php endif; ?></td>
            <td class="mono"><?= e($r['username']) ?></td>
            <td><?= badge($r['role'], $r['role'] === 'admin' ? 'info' : '') ?></td>
            <td><?= tgl($r['last_login']) ?></td>
            <td><?= badge_versi($r['app_versi'] ?? null, $r['app_versi_at'] ?? null) ?></td>
            <td><?= $r['aktif'] ? badge('aktif', 'ok') : badge('nonaktif', 'danger') ?></td>
            <td class="actions"><a class="btn sm ghost" href="?edit=<?= (int)$r['id'] ?>">Ubah</a>
              <?php if ((int)$r['id'] !== admin_id()): ?><form method="post" data-confirm="<?= $r['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?> akun <?= e($r['username']) ?>?"><?= csrf_field() ?><input type="hidden" name="aksi" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn sm ghost"><?= $r['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php layout_bawah();
