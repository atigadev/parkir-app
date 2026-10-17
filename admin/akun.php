<?php
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';
wajib_admin();

$wajib = !empty($_SESSION['wajib_ganti_password']);
$saya = q_one('SELECT * FROM petugas WHERE id = ?', [admin_id()]);

if (is_post()) {
    cek_csrf();
    $lama = (string)($_POST['lama'] ?? '');
    $baru = (string)($_POST['baru'] ?? '');
    $ulang = (string)($_POST['ulang'] ?? '');
    if (!password_verify($lama, $saya['password_hash'])) {
        flash('Password lama salah.', 'err');
    } elseif ($baru !== $ulang) {
        flash('Konfirmasi password baru tidak sama.', 'err');
    } elseif (hash_equals($lama, $baru)) {
        flash('Password baru harus berbeda dari password lama.', 'err');
    } elseif ($galat = cek_password_baru($baru, $saya['username'])) {
        flash($galat, 'err');
    } else {
        // Ganti password & cabut semua sesi aplikasi petugas milik akun ini.
        q('UPDATE petugas SET password_hash = ?, api_token_hash = NULL, token_expires = NULL WHERE id = ?', [password_hash($baru, PASSWORD_DEFAULT), admin_id()]);
        log_aktivitas(admin_id(), 'ganti_password', admin_id());
        session_regenerate_id(true);
        unset($_SESSION['wajib_ganti_password']);
        flash('Password berhasil diganti.');
        redirect(base_url('admin/'));
    }
    redirect('akun.php');
}

layout_atas('Akun saya', 'akun.php');
?>
<?php if ($wajib): ?>
<div class="alert err">Akun ini masih memakai <b>password bawaan instalasi</b>. Demi keamanan, ganti password sekarang sebelum memakai panel admin.</div>
<?php endif; ?>
<div class="card" style="max-width:520px">
  <div class="card-h"><h2>Ganti password</h2><span class="muted"><?= e($saya['nama']) ?> · <?= e($saya['username']) ?></span></div>
  <form class="card-b" method="post" style="display:grid;gap:12px" autocomplete="off">
    <?= csrf_field() ?>
    <label class="f">Password lama<input type="password" name="lama" required autocomplete="current-password"></label>
    <label class="f">Password baru<small>Minimal <?= PASSWORD_MIN ?> karakter, tidak sama dengan username.</small><input type="password" name="baru" minlength="<?= PASSWORD_MIN ?>" required autocomplete="new-password"></label>
    <label class="f">Ulangi password baru<input type="password" name="ulang" minlength="<?= PASSWORD_MIN ?>" required autocomplete="new-password"></label>
    <div class="form-foot"><button class="btn">Simpan password</button></div>
  </form>
</div>
<?php layout_bawah();
