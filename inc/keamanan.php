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

// ---------------------------------------------------------------- header keamanan & CSP
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Nonce CSP per permintaan untuk <script> inline yang sah. */
function csp_nonce(): string
{
    static $n = null;
    if ($n === null) $n = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    return $n;
}

/**
 * Kirim header keamanan. $jenis: 'admin' (halaman HTML dengan skrip ber-nonce), 'api' (JSON), 'file' (gambar).
 */
function header_keamanan(string $jenis): void
{
    if (headers_sent()) return;
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
    if ($jenis === 'admin') {
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . csp_nonce() . "'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    } elseif ($jenis === 'api') {
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    } else {
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'");
    }
}

// ---------------------------------------------------------------- foto kendaraan (URL bertanda tangan)
const FOTO_URL_DETIK = 7200; // tautan foto berlaku 2 jam

/**
 * URL foto kendaraan yang ditandatangani & kedaluwarsa. Foto (plat + kendaraan = data pribadi) tidak bisa
 * dibuka langsung dari folder uploads; hanya lewat foto.php dengan tanda tangan yang sah.
 */
function url_foto_aman(?string $path, int $detik = FOTO_URL_DETIK): ?string
{
    if (!$path) return null;
    $exp = (int)(ceil((time() + $detik) / 600) * 600); // dibulatkan 10 menit agar URL sama bisa di-cache browser
    return base_url('foto.php?f=' . rawurlencode($path) . '&e=' . $exp . '&s=' . tanda_foto($path, $exp));
}

function tanda_foto(string $path, int $exp): string
{
    return substr(hash_hmac('sha256', 'foto|' . $path . '|' . $exp, (string)config('qr_secret')), 0, 32);
}

// ---------------------------------------------------------------- CSV
/** Cegah CSV/formula injection: teks yang diawali = + - @ (bukan angka) diberi awalan apostrof. */
function csv_aman($v)
{
    if ($v === null || is_int($v) || is_float($v)) return $v;
    $v = (string)$v;
    if ($v !== '' && !is_numeric($v) && strpbrk($v[0], "=+-@\t\r") !== false) return "'" . $v;
    return $v;
}

// ---------------------------------------------------------------- sesi aplikasi petugas (per perangkat)
const TOKEN_MAKS_PERANGKAT = 5;

/** Buat token login baru untuk satu perangkat. Token lain milik akun ini tetap berlaku (maks 5 perangkat). */
function token_buat(int $petugasId): string
{
    $token = bin2hex(random_bytes(32));
    $hari = (int)config('token_days', 7);
    if (kolom_tersedia_tabel('petugas_token')) {
        q('DELETE FROM petugas_token WHERE kedaluwarsa < ?', [now()]);
        q('INSERT INTO petugas_token (petugas_id, token_hash, perangkat, ip, dibuat, kedaluwarsa, terakhir_dipakai) VALUES (?,?,?,?,?,?,?)', [
            $petugasId, hash('sha256', $token), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120), client_ip(),
            now(), date('Y-m-d H:i:s', time() + 86400 * $hari), now(),
        ]);
        // Sisakan 5 perangkat terbaru
        $lama = q_all('SELECT id FROM petugas_token WHERE petugas_id = ? ORDER BY dibuat DESC LIMIT 100 OFFSET ' . TOKEN_MAKS_PERANGKAT, [$petugasId]);
        foreach ($lama as $r) q('DELETE FROM petugas_token WHERE id = ?', [$r['id']]);
    } else {
        // Cadangan bila migrasi v4 belum berjalan: satu token per akun (perilaku lama)
        q('UPDATE petugas SET api_token_hash = ?, token_expires = ? WHERE id = ?', [hash('sha256', $token), date('Y-m-d H:i:s', time() + 86400 * $hari), $petugasId]);
    }
    return $token;
}

/** Cari petugas pemilik token (null bila tidak sah/kedaluwarsa/akun nonaktif). Sesi diperpanjang selama dipakai. */
function token_petugas(string $token): ?array
{
    $hash = hash('sha256', strtolower($token));
    $hari = (int)config('token_days', 7);
    if (kolom_tersedia_tabel('petugas_token')) {
        $p = q_one('SELECT p.id, p.nama, p.username, p.role, p.aktif, t.id AS token_id, t.kedaluwarsa, t.terakhir_dipakai
            FROM petugas_token t JOIN petugas p ON p.id = t.petugas_id WHERE t.token_hash = ?', [$hash]);
        if ($p) {
            if (!$p['aktif'] || strtotime($p['kedaluwarsa']) < time()) return null;
            if (strtotime($p['kedaluwarsa']) - time() < 86400 || strtotime((string)$p['terakhir_dipakai']) < time() - 300) {
                q('UPDATE petugas_token SET kedaluwarsa = GREATEST(kedaluwarsa, ?), terakhir_dipakai = ? WHERE id = ?', [date('Y-m-d H:i:s', time() + 86400 * $hari), now(), $p['token_id']]);
            }
            return $p;
        }
    }
    // Sesi lama (sebelum token per perangkat) tetap berlaku sampai kedaluwarsa.
    $p = q_one('SELECT id, nama, username, role, aktif, token_expires FROM petugas WHERE api_token_hash = ?', [$hash]);
    if (!$p || !$p['aktif'] || strtotime((string)$p['token_expires']) < time()) return null;
    if (strtotime((string)$p['token_expires']) - time() < 86400) {
        q('UPDATE petugas SET token_expires = ? WHERE id = ?', [date('Y-m-d H:i:s', time() + 86400 * $hari), $p['id']]);
    }
    return $p;
}

/** Logout satu perangkat. */
function token_cabut(string $token): void
{
    $hash = hash('sha256', strtolower($token));
    if (kolom_tersedia_tabel('petugas_token')) q('DELETE FROM petugas_token WHERE token_hash = ?', [$hash]);
    q('UPDATE petugas SET api_token_hash = NULL, token_expires = NULL WHERE api_token_hash = ?', [$hash]);
}

/** Cabut semua sesi aplikasi milik akun (ganti/reset password, akun dinonaktifkan). */
function token_cabut_semua(int $petugasId): void
{
    if (kolom_tersedia_tabel('petugas_token')) q('DELETE FROM petugas_token WHERE petugas_id = ?', [$petugasId]);
    q('UPDATE petugas SET api_token_hash = NULL, token_expires = NULL WHERE id = ?', [$petugasId]);
}

function jumlah_perangkat_aktif(int $petugasId): int
{
    if (!kolom_tersedia_tabel('petugas_token')) return 0;
    return (int)q_val('SELECT COUNT(*) FROM petugas_token WHERE petugas_id = ? AND kedaluwarsa > ?', [$petugasId, now()]);
}

function kolom_tersedia_tabel(string $tabel): bool
{
    static $cache = [];
    if (!isset($cache[$tabel])) $cache[$tabel] = tabel_ada($tabel);
    return $cache[$tabel];
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
