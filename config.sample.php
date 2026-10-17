<?php
// Salin file ini menjadi config.php lalu sesuaikan.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'parkit',
        'user' => 'root',
        'pass' => '',
    ],
    // Rahasia untuk menandatangani QR tiket (HMAC). Isi dengan string acak panjang,
    // misalnya hasil: php -r "echo bin2hex(random_bytes(32));"
    // JANGAN diubah setelah sistem berjalan: tiket yang sudah tercetak tidak akan valid.
    'qr_secret' => 'GANTI_DENGAN_STRING_ACAK',
    'timezone'  => 'Asia/Jakarta',
    // Masa berlaku token login aplikasi petugas (hari)
    'token_days' => 7,
    // Ukuran maksimal foto upload (byte)
    'max_foto'  => 2 * 1024 * 1024,
    // Batasi API hanya dari jaringan lokal (prefix IP). Kosongkan untuk mengizinkan semua.
    'api_allow_prefix' => [],   // contoh: ['127.0.0.1', '::1', '192.168.'] — biarkan [] di hosting/cPanel
    // Path URL aplikasi. null = deteksi otomatis. Isi '' bila di root (sub)domain, atau '/parkir' bila di subfolder.
    'base_path' => null,
    // true hanya saat pengembangan: menampilkan detail error PHP di layar. JANGAN aktifkan di server produksi.
    'debug' => false,
];
