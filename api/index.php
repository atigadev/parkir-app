<?php
/**
 * REST API untuk aplikasi petugas (PWA).
 * Pemanggilan: api/index.php?r=<rute>, otentikasi: header "Authorization: Bearer <token>".
 * Respons: {"ok":true,"data":...} atau {"ok":false,"error":"...","kode":"..."}
 */
declare(strict_types=1);
require __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function kirim(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok($data = null): void
{
    kirim(['ok' => true, 'data' => $data]);
}

function gagal(string $pesan, string $kode = 'error', int $status = 400, array $data = []): void
{
    kirim(['ok' => false, 'error' => $pesan, 'kode' => $kode, 'data' => $data ?: null], $status);
}

function input(): array
{
    static $in = null;
    if ($in === null) {
        $in = $_POST;
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (stripos($ct, 'application/json') !== false) {
            $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        }
    }
    return $in;
}

function wajib_metode(string $m): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $m) gagal('Metode tidak diizinkan.', 'metode', 405);
}

function petugas_dari_token(): array
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) {
        $hs = array_change_key_case(apache_request_headers(), CASE_LOWER);
        $h = $hs['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($h), $m)) gagal('Sesi tidak valid, silakan login.', 'auth', 401);
    $p = q_one('SELECT id, nama, username, role, aktif, token_expires FROM petugas WHERE api_token_hash = ?', [hash('sha256', strtolower($m[1]))]);
    if (!$p || !$p['aktif'] || strtotime((string)$p['token_expires']) < time()) gagal('Sesi berakhir, silakan login ulang.', 'auth', 401);
    // Perpanjang sesi selama aplikasi dipakai.
    if (strtotime((string)$p['token_expires']) - time() < 86400) {
        q('UPDATE petugas SET token_expires = ? WHERE id = ?', [date('Y-m-d H:i:s', time() + 86400 * (int)config('token_days', 7)), $p['id']]);
    }
    $p['id'] = (int)$p['id'];
    return $p;
}

function wajib_shift(array $p, string $pos): array
{
    $s = shift_aktif($p['id']);
    if (!$s) gagal('Shift belum dibuka.', 'shift', 409);
    if ($s['pos'] !== $pos) gagal('Shift Anda terbuka di pos ' . $s['pos'] . '.', 'shift_pos', 409);
    $s['id'] = (int)$s['id'];
    return $s;
}

function url_foto(?string $path): ?string
{
    return $path ? base_url($path) : null;
}

function data_tiket(array $km): array
{
    return [
        'id'          => $km['id'],
        'kode_qr'     => $km['kode_qr'],
        'plat'        => $km['plat_nomor'],
        'jenis'       => $km['jenis_kendaraan'],
        'waktu_masuk' => $km['waktu_masuk'],
        'pos_masuk'   => $km['pos_masuk'],
        'petugas'     => $km['petugas_nama'] ?? null,
        'status'      => $km['status'],
        'foto'        => url_foto($km['foto_masuk_url'] ?? null),
        'void_diminta'=> !empty($km['void_diminta_at']),
    ];
}

function data_resi_keluar(array $tk): array
{
    return [
        'id'           => (int)$tk['id'],
        'plat'         => $tk['plat_nomor'],
        'jenis'        => $tk['jenis_kendaraan'],
        'waktu_masuk'  => $tk['waktu_masuk'],
        'waktu_keluar' => $tk['waktu_keluar'],
        'durasi_menit' => (int)$tk['durasi_menit'],
        'total'        => (int)$tk['total_biaya'],
        'metode'       => $tk['metode_bayar'],
        'tanpa_tiket'  => (bool)$tk['tanpa_tiket'],
        'petugas'      => $tk['petugas_nama'],
        'rincian'      => $tk['rincian_baris'],
    ];
}

function konfigurasi_klien(): array
{
    $qris = setting('qris_gambar');
    return [
        'nama_lokasi'   => setting('nama_lokasi'),
        'alamat_lokasi' => setting('alamat_lokasi'),
        'footer_resi'   => setting('footer_resi'),
        'qris_gambar'   => $qris ? base_url($qris) . '?v=' . @filemtime(APP_ROOT . '/' . $qris) : null,
        'qris_merchant' => setting('qris_nama_merchant'),
        'denda'         => ['motor' => denda_tanpa_tiket('motor'), 'mobil' => denda_tanpa_tiket('mobil')],
        'ocr_aktif'     => ocr_aktif(),
        'ocr_timeout'   => (int)setting('ocr_timeout', '3'),
        // Untuk pos keluar saat offline
        'tarif'         => q_all('SELECT * FROM tarif WHERE aktif = 1 ORDER BY jenis_kendaraan, berlaku_mulai'),
        'plat_khusus'   => q_all('SELECT plat_nomor, tipe, keterangan, berlaku_sampai FROM plat_khusus WHERE aktif = 1 AND (berlaku_sampai IS NULL OR berlaku_sampai >= CURDATE())'),
        'waktu_server'  => now(),
    ];
}

// ---------------------------------------------------------------------------
$allow = config('api_allow_prefix', []);
if ($allow) {
    $ip = client_ip();
    $izin = false;
    foreach ($allow as $pre) if (strpos($ip, $pre) === 0) $izin = true;
    if (!$izin) gagal('Akses API hanya dari jaringan lokal.', 'ip', 403);
}

$r = (string)($_GET['r'] ?? '');

try {
    switch ($r) {

    // ---------------- Otentikasi -------------------------------------------
    case 'auth.login':
        wajib_metode('POST');
        $in = input();
        $p = q_one('SELECT * FROM petugas WHERE username = ?', [trim((string)($in['username'] ?? ''))]);
        if (!$p || !password_verify((string)($in['password'] ?? ''), $p['password_hash'])) {
            log_aktivitas($p ? (int)$p['id'] : null, 'login_gagal', null, null, ['username' => $in['username'] ?? '', 'via' => 'app']);
            usleep(400000);
            gagal('Username atau password salah.', 'login', 401);
        }
        if (!$p['aktif']) gagal('Akun dinonaktifkan. Hubungi admin.', 'login', 403);
        $token = bin2hex(random_bytes(32));
        q('UPDATE petugas SET api_token_hash = ?, token_expires = ?, last_login = ? WHERE id = ?', [
            hash('sha256', $token), date('Y-m-d H:i:s', time() + 86400 * (int)config('token_days', 7)), now(), $p['id'],
        ]);
        log_aktivitas((int)$p['id'], 'login', null, null, ['via' => 'app']);
        $shift = shift_aktif((int)$p['id']);
        ok([
            'token'   => $token,
            'petugas' => ['id' => (int)$p['id'], 'nama' => $p['nama'], 'username' => $p['username'], 'role' => $p['role']],
            'shift'   => $shift,
            'config'  => konfigurasi_klien(),
        ]);

    case 'auth.logout':
        wajib_metode('POST');
        $p = petugas_dari_token();
        q('UPDATE petugas SET api_token_hash = NULL, token_expires = NULL WHERE id = ?', [$p['id']]);
        log_aktivitas($p['id'], 'logout', null, null, ['via' => 'app']);
        ok();

    case 'me':
        $p = petugas_dari_token();
        ok([
            'petugas' => ['id' => $p['id'], 'nama' => $p['nama'], 'username' => $p['username'], 'role' => $p['role']],
            'shift'   => shift_aktif($p['id']),
            'config'  => konfigurasi_klien(),
        ]);

    // ---------------- Shift ------------------------------------------------
    case 'shift.buka':
        wajib_metode('POST');
        $p = petugas_dari_token();
        $pos = (input()['pos'] ?? '') === 'keluar' ? 'keluar' : 'masuk';
        $posNama = mb_substr(trim((string)(input()['pos_nama'] ?? '')), 0, 50) ?: ($pos === 'masuk' ? 'Pos Masuk' : 'Pos Keluar');
        if ($s = shift_aktif($p['id'])) ok($s);
        q('INSERT INTO shift (petugas_id, pos, pos_nama, waktu_buka) VALUES (?,?,?,?)', [$p['id'], $pos, $posNama, now()]);
        $id = (int)db()->lastInsertId();
        log_aktivitas($p['id'], 'buka_shift', $id, null, ['pos' => $pos, 'pos_nama' => $posNama]);
        ok(q_one('SELECT * FROM shift WHERE id = ?', [$id]));

    case 'shift.ringkasan':
        $p = petugas_dari_token();
        $s = shift_aktif($p['id']);
        if (!$s) gagal('Tidak ada shift terbuka.', 'shift', 409);
        ok(['shift' => $s] + ringkasan_shift((int)$s['id']));

    case 'shift.tutup':
        wajib_metode('POST');
        $p = petugas_dari_token();
        $s = shift_aktif($p['id']);
        if (!$s) gagal('Tidak ada shift terbuka.', 'shift', 409);
        $in = input();
        if (!isset($in['setor']) || !is_numeric($in['setor']) || $in['setor'] < 0) gagal('Isi jumlah uang setoran.', 'setor');
        $sistem = total_cash_shift((int)$s['id']);
        $setor = (int)$in['setor'];
        $selisih = $setor - $sistem;
        q('UPDATE shift SET waktu_tutup = ?, total_cash_sistem = ?, total_cash_setor = ?, selisih = ?, catatan = ? WHERE id = ?', [
            now(), $sistem, $setor, $selisih, mb_substr(trim((string)($in['catatan'] ?? '')), 0, 255) ?: null, $s['id'],
        ]);
        log_aktivitas($p['id'], 'tutup_shift', $s['id'], null, ['sistem' => $sistem, 'setor' => $setor, 'selisih' => $selisih]);
        ok(['shift' => q_one('SELECT * FROM shift WHERE id = ?', [$s['id']])] + ringkasan_shift((int)$s['id']));

    // ---------------- Pos masuk --------------------------------------------
    case 'ocr':
        wajib_metode('POST');
        $p = petugas_dari_token();
        wajib_shift($p, 'masuk');
        $path = simpan_foto($_FILES['foto'] ?? [], 'in_' . bin2hex(random_bytes(8)));
        if (!$path) gagal('Foto tidak valid.', 'foto');
        $hasil = ocr_baca(APP_ROOT . '/' . $path);
        ok(['foto' => $path] + $hasil);

    case 'masuk':
        wajib_metode('POST');
        $p = petugas_dari_token();
        $s = wajib_shift($p, 'masuk');
        $in = input();
        $foto = null;
        if (!empty($in['foto']) && preg_match('#^uploads/foto/\d{4}/\d{2}/\d{2}/in_[a-f0-9]{16}\.(jpg|png|webp)$#', (string)$in['foto'])
            && is_file(APP_ROOT . '/' . $in['foto'])) {
            $foto = (string)$in['foto'];
        } elseif (!empty($_FILES['foto'])) {
            $foto = simpan_foto($_FILES['foto'], 'in_' . bin2hex(random_bytes(8)));
        }
        if (empty($in['paksa']) && ($ada = cek_plat_masih_parkir((string)($in['plat'] ?? '')))) {
            gagal('Plat ini masih tercatat parkir sejak ' . date('d/m H:i', strtotime($ada['waktu_masuk'])) . '.', 'duplikat', 409, $ada);
        }
        $km = catat_masuk([
            'plat' => $in['plat'] ?? '', 'plat_ocr' => $in['plat_ocr'] ?? null, 'jenis' => $in['jenis'] ?? 'motor',
            'foto' => $foto, 'pos_masuk' => $s['pos_nama'] ?: 'Pos Masuk',
        ], $p['id'], $s['id']);
        ok(data_tiket($km));

    case 'masuk.riwayat':
        $p = petugas_dari_token();
        $rows = q_all('SELECT km.*, p.nama AS petugas_nama FROM kendaraan_masuk km JOIN petugas p ON p.id = km.petugas_id
            WHERE km.petugas_id = ? ORDER BY km.waktu_masuk DESC LIMIT 20', [$p['id']]);
        ok(array_map('data_tiket', $rows));

    case 'masuk.void_minta':
        wajib_metode('POST');
        $p = petugas_dari_token();
        $in = input();
        $alasan = trim((string)($in['alasan'] ?? ''));
        if ($alasan === '') gagal('Alasan wajib diisi.', 'alasan');
        $km = masuk_by_id((string)($in['id'] ?? ''));
        if (!$km || $km['status'] !== 'parkir') gagal('Tiket tidak bisa dibatalkan.', 'status');
        q('UPDATE kendaraan_masuk SET void_alasan = ?, void_diminta_oleh = ?, void_diminta_at = ? WHERE id = ?', [mb_substr($alasan, 0, 255), $p['id'], now(), $km['id']]);
        log_aktivitas($p['id'], 'minta_void', $km['id'], null, ['alasan' => $alasan]);
        ok();

    // ---------------- Pos keluar -------------------------------------------
    case 'keluar.scan':
    case 'keluar.hitung':
        wajib_metode('POST');
        $p = petugas_dari_token();
        wajib_shift($p, 'keluar');
        $in = input();
        $tanpaTiket = false;
        if ($r === 'keluar.scan') {
            $urai = qr_urai((string)($in['kode'] ?? ''));
            if (!$urai) gagal('QR tidak dikenali atau palsu.', 'qr_invalid');
            $km = masuk_by_id($urai['id']);
            if (!$km || !hash_equals($km['kode_qr'], trim((string)$in['kode']))) {
                gagal($urai['offline'] ? 'Tiket offline ini belum tersinkron dari pos masuk.' : 'Tiket tidak terdaftar.', 'tidak_ditemukan', 404);
            }
        } else {
            $km = masuk_by_id((string)($in['id'] ?? ''));
            if (!$km) gagal('Data tidak ditemukan.', 'tidak_ditemukan', 404);
            $tanpaTiket = !empty($in['tanpa_tiket']);
        }
        if ($km['status'] === 'keluar') {
            $tk = q_one('SELECT waktu_keluar FROM transaksi_keluar WHERE kendaraan_masuk_id = ?', [$km['id']]);
            gagal('Tiket sudah dipakai keluar pada ' . date('d/m/Y H:i', strtotime($tk['waktu_keluar'])) . '.', 'sudah_keluar', 409);
        }
        if ($km['status'] === 'void') gagal('Tiket ini sudah dibatalkan (void).', 'void', 409);
        $waktu = now();
        $rin = hitung_untuk($km, $waktu, $tanpaTiket);
        ok([
            'tiket'         => data_tiket($km),
            'waktu_keluar'  => $waktu,
            'tanpa_tiket'   => $tanpaTiket,
            'rincian'       => $rin,
            'rincian_baris' => rincian_baris($rin),
            'total'         => $rin['total'],
        ]);

    case 'keluar.cari':
        $p = petugas_dari_token();
        wajib_shift($p, 'keluar');
        $kunci = str_replace(' ', '', normalisasi_plat((string)($_GET['plat'] ?? '')));
        if (strlen($kunci) < 2) gagal('Ketik minimal 2 karakter plat.', 'plat');
        $rows = q_all("SELECT km.*, p.nama AS petugas_nama FROM kendaraan_masuk km JOIN petugas p ON p.id = km.petugas_id
            WHERE km.status = 'parkir' AND REPLACE(km.plat_nomor, ' ', '') LIKE ? ORDER BY km.waktu_masuk DESC LIMIT 20", ['%' . $kunci . '%']);
        ok(array_map('data_tiket', $rows));

    case 'keluar.bayar':
        wajib_metode('POST');
        $p = petugas_dari_token();
        $s = wajib_shift($p, 'keluar');
        $in = input();
        $foto = !empty($_FILES['foto']) ? simpan_foto($_FILES['foto'], 'out_' . bin2hex(random_bytes(8))) : null;
        $tk = catat_keluar([
            'kendaraan_masuk_id' => $in['id'] ?? '', 'metode' => $in['metode'] ?? 'cash', 'waktu_keluar' => $in['waktu_keluar'] ?? null,
            'tanpa_tiket' => !empty($in['tanpa_tiket']), 'catatan' => $in['catatan'] ?? '', 'foto' => $foto,
        ], $p['id'], $s['id']);
        ok(data_resi_keluar($tk));

    case 'keluar.riwayat':
        $p = petugas_dari_token();
        $ids = q_all('SELECT id FROM transaksi_keluar WHERE petugas_id = ? ORDER BY waktu_keluar DESC LIMIT 20', [$p['id']]);
        ok(array_map(function ($x) { return data_resi_keluar(transaksi_keluar_lengkap((int)$x['id'])); }, $ids));

    // ---------------- Sinkronisasi offline ---------------------------------
    case 'sync':
        wajib_metode('POST');
        $p = petugas_dari_token();
        $in = input();
        $hasil = [];
        foreach (($in['items'] ?? []) as $item) {
            $uid = (string)($item['uid'] ?? '');
            try {
                $shiftId = (int)($item['shift_id'] ?? 0);
                $sh = q_one('SELECT id FROM shift WHERE id = ? AND petugas_id = ?', [$shiftId, $p['id']]);
                $shiftId = $sh ? (int)$sh['id'] : null;
                $foto = null;
                if (!empty($item['foto_b64']) && preg_match('#^data:image/jpeg;base64,#', (string)$item['foto_b64'])) {
                    $bin = base64_decode(substr((string)$item['foto_b64'], 23), true);
                    if ($bin !== false && strlen($bin) < config('max_foto', 2097152) && @imagecreatefromstring($bin)) {
                        $rel = 'uploads/foto/' . date('Y/m/d');
                        if (!is_dir(APP_ROOT . '/' . $rel)) mkdir(APP_ROOT . '/' . $rel, 0775, true);
                        $foto = $rel . '/' . ($item['tipe'] === 'masuk' ? 'in_' : 'out_') . bin2hex(random_bytes(8)) . '.jpg';
                        file_put_contents(APP_ROOT . '/' . $foto, $bin);
                    }
                }
                if (($item['tipe'] ?? '') === 'masuk') {
                    $km = catat_masuk([
                        'id' => $item['id'] ?? '', 'kode_qr' => $item['kode_qr'] ?? '', 'waktu_masuk' => $item['waktu_masuk'] ?? '',
                        'plat' => $item['plat'] ?? '', 'plat_ocr' => null, 'jenis' => $item['jenis'] ?? 'motor',
                        'pos_masuk' => $item['pos_masuk'] ?? 'Pos Masuk', 'foto' => $foto, 'offline' => true,
                    ], $p['id'], $shiftId);
                    $hasil[] = ['uid' => $uid, 'ok' => true, 'id' => $km['id']];
                } elseif (($item['tipe'] ?? '') === 'keluar') {
                    $urai = qr_urai((string)($item['kode_qr'] ?? ''));
                    if (!$urai) throw new ParkirException('QR tidak valid / palsu.', 'qr_invalid');
                    $km = masuk_by_id($urai['id']);
                    if (!$km) throw new ParkirException('Tiket masuk belum tersinkron.', 'menunggu');
                    if (!hash_equals($km['kode_qr'], (string)$item['kode_qr'])) throw new ParkirException('QR tidak cocok.', 'qr_invalid');
                    $tk = catat_keluar([
                        'kendaraan_masuk_id' => $km['id'], 'metode' => $item['metode'] ?? 'cash', 'waktu_keluar' => $item['waktu_keluar'] ?? null,
                        'tanpa_tiket' => false, 'catatan' => $item['catatan'] ?? '', 'foto' => $foto,
                        'offline' => true, 'total_offline' => $item['total'] ?? null,
                    ], $p['id'], $shiftId);
                    $hasil[] = ['uid' => $uid, 'ok' => true, 'id' => $tk['id']];
                } else {
                    $hasil[] = ['uid' => $uid, 'ok' => false, 'kode' => 'tipe', 'error' => 'Tipe tidak dikenal'];
                }
            } catch (ParkirException $ex) {
                // 'menunggu' = coba lagi nanti; selain itu item ditolak permanen dan dicatat untuk admin.
                if ($ex->kode !== 'menunggu') {
                    log_aktivitas($p['id'], 'sync_ditolak', $item['id'] ?? null, null, ['item' => array_diff_key($item, ['foto_b64' => 1]), 'error' => $ex->getMessage()]);
                }
                $hasil[] = ['uid' => $uid, 'ok' => false, 'kode' => $ex->kode, 'error' => $ex->getMessage()];
            }
        }
        ok($hasil);

    default:
        gagal('Rute tidak dikenal.', 'rute', 404);
    }
} catch (ParkirException $ex) {
    gagal($ex->getMessage(), $ex->kode, 422, $ex->data);
} catch (Throwable $ex) {
    error_log('[parkit] ' . $ex);
    gagal('Terjadi kesalahan server.', 'server', 500);
}
