<?php
/**
 * Kerangka halaman admin: sesi, CSRF, layout, dan helper form.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

session_name('parkit_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => is_https(), 'path' => base_url('/')]);
session_start();

// Sesi admin berakhir setelah 4 jam tidak ada aktivitas.
const ADMIN_SESI_MENGANGGUR = 4 * 3600;
if (!empty($_SESSION['admin']) && isset($_SESSION['aktif_terakhir']) && time() - $_SESSION['aktif_terakhir'] > ADMIN_SESI_MENGANGGUR) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['aktif_terakhir'] = time();

header_keamanan('admin');

function admin(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function admin_id(): int
{
    return (int)(admin()['id'] ?? 0);
}

function wajib_admin(): void
{
    if (!admin()) {
        header('Location: ' . base_url('admin/login.php'));
        exit;
    }
    if (!empty($_SESSION['wajib_ganti_password']) && basename($_SERVER['SCRIPT_NAME']) !== 'akun.php') {
        header('Location: ' . base_url('admin/akun.php'));
        exit;
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** Dipanggil di awal setiap handler POST. */
function cek_csrf(): void
{
    if (!hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(403);
        exit('Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function flash(string $pesan, string $tipe = 'ok'): void
{
    $_SESSION['flash'][] = [$tipe, $pesan];
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function self_url(array $params = []): string
{
    $q = array_merge($_GET, $params);
    $q = array_filter($q, function ($v) { return $v !== null && $v !== ''; });
    return strtok($_SERVER['REQUEST_URI'], '?') . ($q ? '?' . http_build_query($q) : '');
}

function tgl($dt, string $fmt = 'd/m/Y H:i'): string
{
    static $id = [
        'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu',
        'Mon' => 'Sen', 'Tue' => 'Sel', 'Wed' => 'Rab', 'Thu' => 'Kam', 'Fri' => 'Jum', 'Sat' => 'Sab', 'Sun' => 'Min',
        'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret', 'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli',
        'August' => 'Agustus', 'October' => 'Oktober', 'December' => 'Desember', 'Aug' => 'Agu', 'Oct' => 'Okt', 'Dec' => 'Des',
    ];
    return $dt ? strtr(date($fmt, strtotime((string)$dt)), $id) : '–';
}

function badge(string $teks, string $varian = ''): string
{
    return '<span class="badge ' . e($varian) . '">' . e($teks) . '</span>';
}

function badge_status(string $s): string
{
    $map = ['parkir' => 'info', 'keluar' => 'ok', 'void' => 'danger'];
    return badge($s, $map[$s] ?? '');
}

/** Lencana versi aplikasi petugas dibanding versi terbaru di server. */
function badge_versi(?string $versi, ?string $waktu = null): string
{
    if (!$versi) return '<span class="muted" title="Belum ada data: petugas belum membuka aplikasi versi terbaru">–</span>';
    $terbaru = versi_pwa_terbaru();
    $kapan = $waktu ? '<br><small class="muted">dilaporkan ' . e(tgl($waktu, 'd/m H:i')) . '</small>' : '';
    if ($terbaru && nomor_versi($versi) < nomor_versi($terbaru)) {
        return badge($versi . ' · perlu update', 'warn') . $kapan;
    }
    return badge($versi, 'ok') . $kapan;
}

function pagination(int $total, int $hal, int $per): string
{
    $jml = (int)ceil($total / $per);
    if ($jml <= 1) return '';
    $h = '<nav class="pager"><span class="muted">' . number_format($total, 0, ',', '.') . ' data</span>';
    if ($hal > 1) $h .= '<a class="btn sm ghost" href="' . e(self_url(['hal' => $hal - 1])) . '">‹ Sebelumnya</a>';
    $h .= '<span>Hal ' . $hal . ' / ' . $jml . '</span>';
    if ($hal < $jml) $h .= '<a class="btn sm ghost" href="' . e(self_url(['hal' => $hal + 1])) . '">Berikutnya ›</a>';
    return $h . '</nav>';
}

function ikon(string $nama): string
{
    $p = [
        'dashboard' => '<path d="M3 13h8V3H3zm0 8h8v-6H3zm10 0h8V11h-8zm0-18v6h8V3z"/>',
        'car'       => '<path d="M5 11l1.5-4.5A2 2 0 0 1 8.4 5h7.2a2 2 0 0 1 1.9 1.5L19 11m-14 0h14a2 2 0 0 1 2 2v4h-2m-14-6a2 2 0 0 0-2 2v4h2m0 0v2h3v-2m8 0v2h3v-2M5 17h14M7.5 14h.01M16.5 14h.01"/>',
        'receipt'   => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6M9 15h4"/>',
        'chart'     => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'qr'        => '<path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h3v3h-3zM20 14v.01M14 20h.01M17 17h4v4h-4"/>',
        'tag'       => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8zM7.5 7.5h.01"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        'star'      => '<path d="m12 2 3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
        'eye'       => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
        'list'      => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'gear'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'phone'     => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
        'pin'       => '<path d="M12 22s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
        'printer'   => '<path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$nama] ?? '') . '</svg>';
}

function layout_atas(string $judul, string $aktif = ''): void
{
    $menu = [
        ['index.php', 'dashboard', 'Dashboard'],
        ['parkir.php', 'car', 'Sedang Parkir'],
        ['transaksi.php', 'receipt', 'Transaksi & Void'],
        ['laporan.php', 'chart', 'Laporan'],
        ['shift.php', 'clock', 'Shift & Setoran'],
        ['qris.php', 'qr', 'Rekonsiliasi QRIS'],
        '-',
        ['tarif.php', 'tag', 'Tarif'],
        ['plat_khusus.php', 'star', 'Plat Khusus'],
        ['petugas.php', 'users', 'Petugas'],
        ['pos.php', 'pin', 'Pos & Gerbang'],
        ['ocr.php', 'eye', 'Kualitas OCR'],
        ['log.php', 'list', 'Log Aktivitas'],
        ['resi.php', 'printer', 'Tampilan Resi'],
        ['pengaturan.php', 'gear', 'Pengaturan'],
    ];
    $voidPending = (int)q_val("SELECT COUNT(*) FROM kendaraan_masuk WHERE void_diminta_at IS NOT NULL AND status <> 'void' AND void_oleh IS NULL");
    $a = admin();
    ?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($judul) ?> · ParkIt Admin</title>
<link rel="icon" href="<?= e(base_url('pwa/icons/icon.svg')) ?>">
<link rel="stylesheet" href="<?= e(base_url('admin/assets/admin.css')) ?>?v=4">
</head>
<body>
<div class="shell">
  <aside class="side" id="side">
    <div class="brand"><span class="logo">P</span><div><b>ParkIt</b><small><?= e(setting('nama_lokasi')) ?></small></div></div>
    <nav>
    <?php foreach ($menu as $m): if ($m === '-') { echo '<hr>'; continue; } ?>
      <a href="<?= e(base_url('admin/' . $m[0])) ?>" class="<?= $aktif === $m[0] ? 'on' : '' ?>"><?= ikon($m[1]) ?><span><?= e($m[2]) ?></span>
        <?php if ($m[0] === 'transaksi.php' && $voidPending): ?><em class="count" title="Permintaan void menunggu"><?= $voidPending ?></em><?php endif; ?>
      </a>
    <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <a href="<?= e(base_url('pwa/')) ?>" target="_blank"><?= ikon('phone') ?><span>Aplikasi Petugas</span></a>
      <div class="me"><span class="avatar"><?= e(mb_strtoupper(mb_substr($a['nama'], 0, 1))) ?></span><div><b><?= e($a['nama']) ?></b><small><a href="<?= e(base_url('admin/akun.php')) ?>" style="color:inherit">Akun saya · ganti password</a></small></div>
        <form method="post" action="<?= e(base_url('admin/logout.php')) ?>"><?= csrf_field() ?><button class="icon-btn" title="Keluar"><?= ikon('logout') ?></button></form></div>
    </div>
  </aside>
  <main class="main">
    <header class="top"><button class="icon-btn menu-btn" data-aksi="menu" aria-label="Menu">☰</button><h1><?= e($judul) ?></h1></header>
    <?php foreach ($_SESSION['flash'] ?? [] as [$t, $p]): ?><div class="alert <?= e($t) ?>"><?= e($p) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
<?php
}

function layout_bawah(): void
{
    ?>
  </main>
</div>
<dialog class="lightbox" id="lightbox" aria-label="Foto kendaraan">
  <div class="lb-bar">
    <span class="lb-cap" id="lb-cap"></span>
    <a class="btn sm ghost" id="lb-asli" href="#" target="_blank" rel="noopener">Ukuran asli</a>
    <button type="button" class="icon-btn lb-x" id="lb-x" aria-label="Tutup">✕</button>
  </div>
  <div class="lb-stage" id="lb-stage">
    <button type="button" class="lb-nav prev" id="lb-prev" aria-label="Foto sebelumnya">‹</button>
    <img id="lb-img" alt="">
    <button type="button" class="lb-nav next" id="lb-next" aria-label="Foto berikutnya">›</button>
  </div>
  <div class="lb-hint muted">Klik foto untuk zoom · Esc untuk menutup</div>
</dialog>
<script nonce="<?= e(csp_nonce()) ?>">
document.addEventListener('submit', function (e) {
  var m = e.target.getAttribute('data-confirm');
  if (m && !confirm(m)) e.preventDefault();
});
// Pengganti handler inline (diblokir CSP): menu, kirim form otomatis, navigasi dari input, pilih semua.
document.addEventListener('click', function (e) {
  var t = e.target.closest('[data-aksi="menu"]');
  if (t) document.getElementById('side').classList.toggle('open');
  var ps = e.target.closest('[data-pilih-semua]');
  if (ps) document.querySelectorAll(ps.getAttribute('data-pilih-semua')).forEach(function (c) { c.checked = ps.checked; });
});
document.addEventListener('change', function (e) {
  if (e.target.matches('[data-auto-submit]')) e.target.form.submit();
  var nav = e.target.getAttribute('data-navigasi');
  if (nav) location = nav + encodeURIComponent(e.target.value);
});

// Lightbox foto: link dengan atribut data-foto dibuka di modal, bukan halaman baru.
(function () {
  var dlg = document.getElementById('lightbox');
  if (!dlg || !dlg.showModal) return; // browser lama: link tetap membuka foto biasa
  var img = document.getElementById('lb-img'), cap = document.getElementById('lb-cap'), asli = document.getElementById('lb-asli');
  var prev = document.getElementById('lb-prev'), next = document.getElementById('lb-next');
  var daftar = [], idx = 0;

  function tampil(i) {
    idx = (i + daftar.length) % daftar.length;
    var a = daftar[idx];
    img.classList.remove('zoom');
    img.src = a.href;
    cap.textContent = a.getAttribute('data-foto') + (daftar.length > 1 ? '  (' + (idx + 1) + '/' + daftar.length + ')' : '');
    asli.href = a.href;
    prev.hidden = next.hidden = daftar.length < 2;
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-foto]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    var grup = a.getAttribute('data-grup');
    daftar = grup ? Array.prototype.slice.call(document.querySelectorAll('a[data-foto][data-grup="' + grup + '"]')) : [a];
    tampil(daftar.indexOf(a));
    dlg.showModal();
  });

  document.getElementById('lb-x').addEventListener('click', function () { dlg.close(); });
  prev.addEventListener('click', function () { tampil(idx - 1); });
  next.addEventListener('click', function () { tampil(idx + 1); });
  img.addEventListener('click', function () { img.classList.toggle('zoom'); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg || e.target.id === 'lb-stage') dlg.close(); });
  dlg.addEventListener('keydown', function (e) {
    if (daftar.length < 2) return;
    if (e.key === 'ArrowLeft') tampil(idx - 1);
    if (e.key === 'ArrowRight') tampil(idx + 1);
  });
  dlg.addEventListener('close', function () { img.removeAttribute('src'); });
})();
</script>
</body>
</html>
<?php
}
