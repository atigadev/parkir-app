<?php
/**
 * Fungsi keamanan bersama: pembatasan percobaan login, aturan password, token hitung tarif.
 */
declare(strict_types=1);

const LOGIN_MAKS_GAGAL_AKUN = 5;    // per username dalam jendela waktu
const LOGIN_MAKS_GAGAL_IP   = 20;   // per alamat IP dalam jendela waktu (menahan tebak banyak akun)
const LOGIN_JENDELA_DETIK   = 900;  // 15 menit
const PASSWORD_MIN          = 8;
const TOKEN_HITUNG_DETIK    = 300;  // token hitung dari scan berlaku 5 menit

/**
 * Sisa detik penguncian login untuk username/IP ini (0 = boleh mencoba).
 * Hanya kegagalan setelah login berhasil terakhir (dalam jendela 15 menit) yang dihitung.
 */
function login_terkunci(string $username, string $ip): int
{
    $batas = date('Y-m-d H:i:s', time() - LOGIN_JENDELA_DETIK);
    $u = mb_strtolower(trim($username));
    $sukses = q_val("SELECT MAX(waktu) FROM log_aktivitas WHERE aksi = 'login' AND referensi_id = ?", [$u]);
    $sejak = $sukses && $sukses > $batas ? $sukses : $batas;

    $akun = q_one("SELECT COUNT(*) n, MAX(waktu) akhir FROM log_aktivitas WHERE aksi = 'login_gagal' AND referensi_id = ? AND waktu > ?", [$u, $sejak]);
    $perIp = q_one("SELECT COUNT(*) n, MAX(waktu) akhir FROM log_aktivitas WHERE aksi = 'login_gagal' AND ip = ? AND waktu > ?", [$ip, $batas]);

    $sisa = 0;
    if ((int)$akun['n'] >= LOGIN_MAKS_GAGAL_AKUN) $sisa = max($sisa, strtotime($akun['akhir']) + LOGIN_JENDELA_DETIK - time());
    if ((int)$perIp['n'] >= LOGIN_MAKS_GAGAL_IP) $sisa = max($sisa, strtotime($perIp['akhir']) + LOGIN_JENDELA_DETIK - time());
    return max(0, $sisa);
}

function pesan_terkunci(int $detik): string
{
    return 'Terlalu banyak percobaan login gagal. Coba lagi dalam ' . max(1, (int)ceil($detik / 60)) . ' menit, atau hubungi admin.';
}

/** Catat login gagal/berhasil. Username disimpan di referensi_id agar bisa dihitung per akun. */
function catat_login(bool $berhasil, ?int $petugasId, string $username, string $via, array $tambahan = []): void
{
    log_aktivitas($petugasId, $berhasil ? 'login' : 'login_gagal', mb_substr(mb_strtolower(trim($username)), 0, 40), null, ['via' => $via] + $tambahan);
}

/** Kembalikan pesan kesalahan bila password baru tidak memenuhi aturan, atau null bila valid. */
function cek_password_baru(string $password, string $username = ''): ?string
{
    if (strlen($password) < PASSWORD_MIN) return 'Password minimal ' . PASSWORD_MIN . ' karakter.';
    if ($username !== '' && strcasecmp($password, $username) === 0) return 'Password tidak boleh sama dengan username.';
    if (in_array(strtolower($password), ['admin123', 'password', '12345678', 'parkir123', 'qwerty123'], true)) return 'Password terlalu mudah ditebak.';
    return null;
}

/** Password masih password bawaan instalasi (harus diganti). */
function password_bawaan(string $hash): bool
{
    return password_verify('admin123', $hash);
}

// ---------------------------------------------------------------- token hitung
/**
 * Token yang diberikan saat scan/hitung di pos keluar. Saat bayar, server memakai waktu keluar dari token ini,
 * bukan dari HP, sehingga waktu keluar tidak bisa dimundurkan untuk memperkecil tarif.
 */
function token_hitung_buat(string $kmId, string $waktuKeluar, bool $tanpaTiket): string
{
    $ts = (string)strtotime($waktuKeluar);
    $data = $kmId . '|' . $ts . '|' . ($tanpaTiket ? '1' : '0');
    return $ts . '.' . ($tanpaTiket ? '1' : '0') . '.' . substr(hash_hmac('sha256', 'hitung|' . $data, (string)config('qr_secret')), 0, 24);
}

/**
 * Verifikasi token hitung. Mengembalikan waktu keluar ('Y-m-d H:i:s') bila token sah, untuk tiket & mode
 * (tanpa tiket) yang sama, dan belum kedaluwarsa; null bila tidak.
 */
function token_hitung_waktu(?string $token, string $kmId, bool $tanpaTiket): ?string
{
    if (!$token || !preg_match('/^(\d{9,11})\.([01])\.([0-9a-f]{24})$/', $token, $m)) return null;
    [, $ts, $tt, $tanda] = $m;
    if ($tt !== ($tanpaTiket ? '1' : '0')) return null;
    $harap = substr(hash_hmac('sha256', 'hitung|' . $kmId . '|' . $ts . '|' . $tt, (string)config('qr_secret')), 0, 24);
    if (!hash_equals($harap, $tanda)) return null;
    $ts = (int)$ts;
    if ($ts > time() + 60 || $ts < time() - TOKEN_HITUNG_DETIK) return null;
    return date('Y-m-d H:i:s', $ts);
}
