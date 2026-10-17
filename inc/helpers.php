<?php
declare(strict_types=1);

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function rupiah($n): string
{
    return 'Rp' . number_format((int)$n, 0, ',', '.');
}

function durasi_teks(int $menit): string
{
    $h = intdiv($menit, 1440);
    $j = intdiv($menit % 1440, 60);
    $m = $menit % 60;
    $out = [];
    if ($h) $out[] = $h . ' hr';
    if ($j) $out[] = $j . ' j';
    $out[] = $m . ' m';
    return implode(' ', $out);
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Nomor tiket yang mudah dibaca/diketik: 8 karakter pertama UUID, mis. "6B95-A60D". */
function no_tiket(string $id): string
{
    return strtoupper(substr($id, 0, 4) . '-' . substr($id, 4, 4));
}

/** Nomor struk keluar, mis. "S261017-000123". */
function no_struk(int $id, string $waktuKeluar): string
{
    return 'S' . date('ymd', strtotime($waktuKeluar)) . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
}

function is_uuid(string $s): bool
{
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $s);
}

/**
 * Normalisasi plat Indonesia: "b1234abc" / "B-1234-ABC" -> "B 1234 ABC".
 * Plat yang tidak cocok pola standar tetap dikembalikan huruf besar tanpa simbol.
 */
function normalisasi_plat(?string $plat): string
{
    $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$plat));
    if (preg_match('/^([A-Z]{1,2})(\d{1,4})([A-Z]{0,3})$/', $p, $m)) {
        return trim($m[1] . ' ' . $m[2] . ' ' . $m[3]);
    }
    return substr($p, 0, 15);
}

function setting(string $kunci, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q_all('SELECT kunci, nilai FROM pengaturan') as $r) {
            $cache[$r['kunci']] = $r['nilai'];
        }
    }
    return $cache[$kunci] ?? $default;
}

function set_setting(string $kunci, string $nilai): void
{
    q('INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?) ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)', [$kunci, $nilai]);
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'cli';
}

function log_aktivitas(?int $petugasId, string $aksi, $refId = null, $sebelum = null, $sesudah = null): void
{
    q('INSERT INTO log_aktivitas (petugas_id, aksi, referensi_id, data_sebelum, data_sesudah, waktu, ip) VALUES (?,?,?,?,?,?,?)', [
        $petugasId,
        $aksi,
        $refId === null ? null : (string)$refId,
        $sebelum === null ? null : (is_string($sebelum) ? $sebelum : json_encode($sebelum, JSON_UNESCAPED_UNICODE)),
        $sesudah === null ? null : (is_string($sesudah) ? $sesudah : json_encode($sesudah, JSON_UNESCAPED_UNICODE)),
        now(),
        client_ip(),
    ]);
}

/** URL dasar aplikasi (mendukung akses via IP LAN maupun localhost). */
function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $base = config('base_path');
        if ($base === null) {
            $root = str_replace('\\', '/', APP_ROOT);
            $doc = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
            $base = ($doc !== '' && strncasecmp($root, $doc, strlen($doc)) === 0) ? substr($root, strlen($doc)) : '';
        }
        $base = rtrim($base, '/');
    }
    return $base . '/' . ltrim($path, '/');
}

/**
 * Simpan foto upload (JPEG) ke uploads/foto/YYYY/MM/DD/<nama>.jpg.
 * Mengembalikan path relatif terhadap root aplikasi, atau null bila gagal.
 */
function simpan_foto(array $file, string $nama): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > config('max_foto', 2097152)) return null;
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return null;
    $ext = $info[2] === IMAGETYPE_PNG ? 'png' : ($info[2] === IMAGETYPE_WEBP ? 'webp' : 'jpg');
    $rel = 'uploads/foto/' . date('Y/m/d');
    $dir = APP_ROOT . '/' . $rel;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) return null;
    $nama = preg_replace('/[^a-zA-Z0-9_-]/', '', $nama);
    $path = "$rel/$nama.$ext";
    if (!move_uploaded_file($file['tmp_name'], APP_ROOT . '/' . $path)) return null;
    return $path;
}
