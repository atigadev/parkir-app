<?php
/**
 * Isi QR tiket: PK1.<uuid>.<M|C>.<waktu_masuk unix base36>.<tanda>
 *   M = motor, C = mobil (car)
 *   tanda = 16 hex pertama HMAC-SHA256(secret, "uuid|jenis|ts") untuk tiket online,
 *           atau "OFF" + 13 hex acak untuk tiket yang dibuat saat pos masuk offline.
 *
 * QR tidak memuat plat. Jenis dan waktu masuk disertakan agar pos keluar tetap
 * bisa menghitung tarif saat offline; server selalu memverifikasi ulang terhadap
 * database (kode_qr harus sama persis dengan yang tersimpan).
 */
declare(strict_types=1);

function qr_buat(string $id, string $jenis, string $waktuMasuk): string
{
    $j  = $jenis === 'mobil' ? 'C' : 'M';
    $ts = base_convert((string)strtotime($waktuMasuk), 10, 36);
    return "PK1.$id.$j.$ts." . qr_tanda($id, $j, $ts);
}

function qr_tanda(string $id, string $j, string $ts): string
{
    return substr(hash_hmac('sha256', "$id|$j|$ts", (string)config('qr_secret')), 0, 16);
}

/**
 * Urai isi QR. Mengembalikan [id, jenis, waktu_masuk, offline] atau null bila
 * format salah atau tanda tangan tidak cocok.
 */
function qr_urai(string $kode): ?array
{
    // QR dicetak dalam huruf besar (mode alphanumeric: modul lebih besar, lebih mudah dipindai
    // di kertas thermal). Normalisasi ke bentuk kanonik huruf kecil seperti yang tersimpan di database.
    $p = explode('.', trim($kode));
    if (count($p) !== 5) return null;
    $p[0] = strtoupper($p[0]);
    $p[1] = strtolower($p[1]);
    $p[2] = strtoupper($p[2]);
    $p[3] = strtolower($p[3]);
    $p[4] = strtoupper(substr($p[4], 0, 3)) === 'OFF' ? 'OFF' . strtolower(substr($p[4], 3)) : strtolower($p[4]);
    if ($p[0] !== 'PK1' || !is_uuid($p[1]) || !in_array($p[2], ['M', 'C'], true)) {
        return null;
    }
    [, $id, $j, $ts, $tanda] = $p;
    if (!preg_match('/^[0-9a-z]{1,10}$/', $ts)) return null;
    $offline = strncmp($tanda, 'OFF', 3) === 0;
    if (!$offline && !hash_equals(qr_tanda($id, $j, $ts), $tanda)) {
        return null;
    }
    return [
        'id'          => $id,
        'jenis'       => $j === 'C' ? 'mobil' : 'motor',
        'waktu_masuk' => date('Y-m-d H:i:s', (int)base_convert($ts, 36, 10)),
        'offline'     => $offline,
        'kode'        => implode('.', $p), // bentuk kanonik, untuk dicocokkan dengan kendaraan_masuk.kode_qr
    ];
}
