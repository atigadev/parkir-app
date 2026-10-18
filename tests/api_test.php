<?php
/**
 * Uji API ujung-ke-ujung: alur normal + skenario kecurangan/serangan dari laporan QA.
 *
 *   php tests/api_test.php [URL_APLIKASI]       default: http://localhost/parkit-app
 *
 * Membuat akun & data uji sementara (petugas id 990–993, plat "QA ...") lalu menghapusnya.
 * HANYA untuk server lokal/staging: menolak berjalan ke alamat selain localhost kecuali diberi --yakin.
 * --server-bawaan: untuk php -S (tanpa .htaccess) — uji blokir akses langsung folder foto dilewati.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
require __DIR__ . '/../inc/bootstrap.php';

$args = array_values(array_filter(array_slice($argv, 1), function ($a) { return $a[0] !== '-'; }));
// --server-bawaan: server bawaan PHP (php -S) tidak membaca .htaccess, jadi uji blokir folder dilewati.
$SERVER_BAWAAN = in_array('--server-bawaan', $argv, true);
$BASE = rtrim($args[0] ?? 'http://localhost/parkit-app', '/');
if (!preg_match('#^https?://(localhost|127\.0\.0\.1)([:/]|$)#', $BASE) && !in_array('--yakin', $argv, true)) {
    fwrite(STDERR, "Menolak menguji $BASE (bukan localhost). Tambahkan --yakin bila ini server staging.\n");
    exit(2);
}

$gagal = 0;
function cek(string $nama, bool $ok, string $info = ''): void
{
    global $gagal;
    if (!$ok) $gagal++;
    printf("%s %s%s\n", $ok ? 'LULUS' : 'GAGAL', $nama, $info !== '' ? "  ($info)" : '');
}
/** Panggil API. $body array -> JSON, atau ['_form' => [...]] -> multipart. */
function api(string $rute, ?string $token = null, $body = null, array $header = []): array
{
    global $BASE;
    $ch = curl_init("$BASE/api/index.php?r=$rute");
    $h = $header;
    if ($token) $h[] = "Authorization: Bearer $token";
    if (is_array($body) && isset($body['_form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body['_form']);
    } elseif ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20]);
    $res = curl_exec($ch);
    $kode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $j = json_decode((string)$res, true) ?: ['ok' => false, 'error' => 'respons bukan JSON: ' . substr((string)$res, 0, 120)];
    $j['_http'] = $kode;
    return $j;
}
function jkt(int $ts): string { return date('Y-m-d H:i:s', $ts); }

const PASS = 'UjiQa#2026';
$akun = [990 => ['qa_masuk', 'masuk'], 991 => ['qa_keluar', 'keluar'], 992 => ['qa_kunci', null], 993 => ['qa_dua_hp', null]];

function bersihkan(): void
{
    if (kolom_tersedia_tabel('pos')) q("DELETE FROM pos WHERE nama LIKE 'QA-UJI %'");
    q("DELETE tk FROM transaksi_keluar tk JOIN kendaraan_masuk km ON km.id = tk.kendaraan_masuk_id WHERE km.petugas_id BETWEEN 990 AND 993 OR tk.petugas_id BETWEEN 990 AND 993");
    q("DELETE FROM kendaraan_masuk WHERE petugas_id BETWEEN 990 AND 993");
    q("DELETE FROM shift WHERE petugas_id BETWEEN 990 AND 993");
    q("DELETE FROM log_aktivitas WHERE petugas_id BETWEEN 990 AND 993 OR referensi_id LIKE 'qa\\_%'");
    q("DELETE FROM petugas WHERE id BETWEEN 990 AND 993");
}

try {
    bersihkan();
    foreach ($akun as $id => [$u]) {
        q("INSERT INTO petugas (id, nama, username, password_hash, role) VALUES (?, ?, ?, ?, 'petugas')", [$id, 'QA ' . $u, $u, password_hash(PASS, PASSWORD_DEFAULT)]);
    }
    $login = function (string $u, string $p = PASS): array { return api('auth.login', null, ['username' => $u, 'password' => $p]); };
    $tm = $login('qa_masuk')['data']['token'] ?? '';
    $tk = $login('qa_keluar')['data']['token'] ?? '';
    cek('0. login petugas uji', $tm !== '' && $tk !== '');
    // ---------------------------------------------------------------- pos & gerbang
    $posBaru = function (string $nama, string $jenis, array $petugas = [], int $aktif = 1): int {
        q('INSERT INTO pos (nama, jenis, aktif, dibuat) VALUES (?, ?, ?, ?)', [$nama, $jenis, $aktif, now()]);
        $id = (int)db()->lastInsertId();
        foreach ($petugas as $pid) q('INSERT INTO pos_petugas (pos_id, petugas_id) VALUES (?, ?)', [$id, $pid]);
        return $id;
    };
    $posM = $posBaru('QA-UJI Masuk', 'masuk', [990]);
    $posK = $posBaru('QA-UJI Keluar', 'keluar', [991]);
    $posLain = $posBaru('QA-UJI Lain', 'keluar');
    $posMati = $posBaru('QA-UJI Nonaktif', 'masuk', [990], 0);
    $idPos = function (array $r) { return array_column($r['data']['pos_tersedia'] ?? [], 'id'); };
    $lihatM = $idPos(api('me', $tm));
    cek('P1. petugas yang ditugaskan hanya melihat pos tugasnya (pos nonaktif tidak tampil)', $lihatM === [$posM]);
    $lihatBebas = $idPos(api('me', $login('qa_dua_hp')['data']['token'] ?? ''));
    cek('P2. petugas tanpa penugasan melihat semua pos aktif', in_array($posM, $lihatBebas, true) && in_array($posK, $lihatBebas, true) && in_array($posLain, $lihatBebas, true) && !in_array($posMati, $lihatBebas, true));
    $r = api('shift.buka', $tm, ['pos_id' => $posK]);
    cek('P3. membuka shift di pos yang bukan tugasnya ditolak', !($r['ok'] ?? true) && ($r['kode'] ?? '') === 'pos_tidak_boleh');
    $r = api('shift.buka', $tm, ['pos_id' => $posMati]);
    cek('P4. pos nonaktif tidak bisa dipakai', !($r['ok'] ?? true) && ($r['kode'] ?? '') === 'pos_tidak_boleh');
    $r = api('shift.buka', $tm, ['pos' => 'masuk', 'pos_nama' => 'Ketik bebas']);
    cek('P5. isian nama pos bebas (aplikasi lama) ditolak bila daftar pos sudah ada', !($r['ok'] ?? true) && ($r['kode'] ?? '') === 'pos_wajib');
    $r = api('shift.buka', $tm, ['pos_id' => $posM]);
    cek('P6. buka shift di pos tugas: jenis & nama dari daftar admin', ($r['ok'] ?? false) && $r['data']['pos'] === 'masuk' && $r['data']['pos_nama'] === 'QA-UJI Masuk' && (int)$r['data']['pos_id'] === $posM);
    api('shift.buka', $tk, ['pos_id' => $posK]);
    $info = array_column(pos_untuk_petugas(993), null, 'id');
    cek('P7. daftar pos menunjukkan petugas lain yang sedang membuka shift di pos itu', ($info[$posK]['dipakai_oleh'] ?? []) === ['QA qa_keluar']);
    $tiket = function (string $plat, int $mundurMenit = 0) use ($tm): array {
        $d = api('masuk', $tm, ['_form' => ['plat' => $plat, 'jenis' => 'motor', 'paksa' => '1']])['data'];
        if ($mundurMenit) q('UPDATE kendaraan_masuk SET waktu_masuk = ? WHERE id = ?', [jkt(time() - $mundurMenit * 60), $d['id']]);
        return $d;
    };

    // ---------------------------------------------------------------- alur normal
    $t = $tiket('QA 1 AA', 135);
    $scan = api('keluar.scan', $tk, ['kode' => $t['kode_qr']]);
    cek('N1. scan QR mengembalikan tarif & token hitung', ($scan['ok'] ?? false) && !empty($scan['data']['token_hitung']), 'Rp' . ($scan['data']['total'] ?? '?'));
    $bayar = api('keluar.bayar', $tk, ['_form' => ['id' => $t['id'], 'metode' => 'cash', 'token_hitung' => $scan['data']['token_hitung']]]);
    cek('N2. bayar dengan token: tarif sama dengan saat scan', ($bayar['ok'] ?? false) && $bayar['data']['total'] === $scan['data']['total'], 'Rp' . ($bayar['data']['total'] ?? '?'));
    $ulang = api('keluar.scan', $tk, ['kode' => $t['kode_qr']]);
    cek('N3. tiket yang sudah keluar ditolak saat discan ulang', !($ulang['ok'] ?? true) && ($ulang['kode'] ?? '') === 'sudah_keluar');

    // ---------------------------------------------------------------- #3 waktu keluar dimundurkan
    $t = $tiket('QA 3 AA', 135);
    $b = api('keluar.bayar', $tk, ['_form' => ['id' => $t['id'], 'metode' => 'cash', 'waktu_keluar' => jkt(time() - 840)]]);
    cek('#3a. waktu keluar dimundurkan 14 mnt TANPA token -> diabaikan', ($b['ok'] ?? false) && $b['data']['durasi_menit'] >= 134, ($b['data']['durasi_menit'] ?? '?') . ' mnt');
    $t = $tiket('QA 3 BB', 135);
    $s = api('keluar.scan', $tk, ['kode' => $t['kode_qr']]);
    [$ts, $tt, $tanda] = explode('.', $s['data']['token_hitung']);
    $palsu = ($ts - 840) . '.' . $tt . '.' . $tanda;
    $b = api('keluar.bayar', $tk, ['_form' => ['id' => $t['id'], 'metode' => 'cash', 'token_hitung' => $palsu]]);
    cek('#3b. token hitung diubah (waktu dimundurkan) -> ditolak, pakai waktu sekarang', ($b['ok'] ?? false) && $b['data']['durasi_menit'] >= 134, ($b['data']['durasi_menit'] ?? '?') . ' mnt');
    $t2 = $tiket('QA 3 CC', 60);
    $b = api('keluar.bayar', $tk, ['_form' => ['id' => $t2['id'], 'metode' => 'cash', 'token_hitung' => $s['data']['token_hitung']]]);
    cek('#3c. token milik tiket lain tidak berlaku', ($b['ok'] ?? false) && $b['data']['durasi_menit'] >= 59);

    // ---------------------------------------------------------------- #1 pembayaran offline palsu
    $t = $tiket('QA 1 BB', 300);
    $sync = api('sync', $tk, ['items' => [['uid' => 'k-qa1', 'tipe' => 'keluar', 'kode_qr' => $t['kode_qr'], 'metode' => 'cash', 'total' => 0, 'waktu_keluar' => now(), 'shift_id' => 0]]]);
    $row = q_one('SELECT total_biaya, total_offline FROM transaksi_keluar WHERE kendaraan_masuk_id = ?', [$t['id']]);
    cek('#1a. tiket online dikirim "offline" Rp0 -> tagihan tetap hitungan server', ($sync['ok'] ?? false) && $row && (int)$row['total_biaya'] > 0 && (int)$row['total_offline'] === 0, 'tagihan Rp' . ($row['total_biaya'] ?? '?') . ', HP Rp' . ($row['total_offline'] ?? '?'));
    $shiftK = (int)q_val('SELECT id FROM shift WHERE petugas_id = 991 AND waktu_tutup IS NULL');
    q('UPDATE transaksi_keluar SET shift_id = ? WHERE kendaraan_masuk_id = ?', [$shiftK, $t['id']]);
    cek('#1b. setoran yang diharapkan shift ikut hitungan server', total_cash_shift($shiftK) >= (int)$row['total_biaya']);
    cek('#1c. tercatat di log sebagai offline_berselisih', (bool)q_val("SELECT COUNT(*) FROM log_aktivitas WHERE aksi = 'offline_berselisih' AND referensi_id = ?", [$t['id']]));

    // ---------------------------------------------------------------- #2 waktu masuk offline janggal
    $uuid = uuid4(); $tsF = time() + 5 * 3600;
    $kode = "PK1.$uuid.M." . base_convert((string)$tsF, 10, 36) . '.OFF0123456789abc';
    $shiftM = (int)q_val('SELECT id FROM shift WHERE petugas_id = 990 AND waktu_tutup IS NULL');
    api('sync', $tm, ['items' => [['uid' => 'm-qa2', 'tipe' => 'masuk', 'id' => $uuid, 'kode_qr' => $kode, 'plat' => 'QA 2 AA', 'jenis' => 'motor', 'waktu_masuk' => jkt($tsF), 'shift_id' => $shiftM]]]);
    $km = q_one('SELECT waktu_masuk, catatan_sistem FROM kendaraan_masuk WHERE id = ?', [$uuid]);
    cek('#2a. tiket offline berjam masa depan -> waktu masuk dikoreksi & ditandai', $km && strtotime($km['waktu_masuk']) <= time() + 5 && !empty($km['catatan_sistem']), $km['catatan_sistem'] ?? '');
    $uuid2 = uuid4(); $tsL = time() - 3 * 86400;
    $kode2 = "PK1.$uuid2.M." . base_convert((string)$tsL, 10, 36) . '.OFF0123456789abd';
    api('sync', $tm, ['items' => [['uid' => 'm-qa3', 'tipe' => 'masuk', 'id' => $uuid2, 'kode_qr' => $kode2, 'plat' => 'QA 2 BB', 'jenis' => 'motor', 'waktu_masuk' => jkt($tsL), 'shift_id' => $shiftM]]]);
    $km2 = q_one('SELECT waktu_masuk, catatan_sistem FROM kendaraan_masuk WHERE id = ?', [$uuid2]);
    $bukaM = q_val('SELECT waktu_buka FROM shift WHERE id = ?', [$shiftM]);
    cek('#2b. tiket offline sebelum shift dibuka -> dikoreksi ke waktu buka shift', $km2 && $km2['waktu_masuk'] === $bukaM && !empty($km2['catatan_sistem']));
    $uuid3 = uuid4(); $tsN = time() - 120;
    $kode3 = "PK1.$uuid3.M." . base_convert((string)$tsN, 10, 36) . '.OFF0123456789abe';
    api('sync', $tm, ['items' => [['uid' => 'm-qa4', 'tipe' => 'masuk', 'id' => $uuid3, 'kode_qr' => $kode3, 'plat' => 'QA 2 CC', 'jenis' => 'motor', 'waktu_masuk' => jkt($tsN), 'shift_id' => $shiftM]]]);
    $km3 = q_one('SELECT waktu_masuk, catatan_sistem FROM kendaraan_masuk WHERE id = ?', [$uuid3]);
    cek('#2c. tiket offline normal tidak diubah', $km3 && $km3['waktu_masuk'] === jkt($tsN) && $km3['catatan_sistem'] === null);

    // ---------------------------------------------------------------- #4 brute force login
    for ($i = 0; $i < 5; $i++) $login('qa_kunci', 'salah' . $i);
    $r = $login('qa_kunci');
    cek('#4a. setelah 5 password salah, akun dikunci (password benar pun ditolak)', !($r['ok'] ?? true) && ($r['kode'] ?? '') === 'terkunci' && $r['_http'] === 429, $r['error'] ?? '');
    $r2 = $login('qa_dua_hp');
    cek('#4b. akun lain dari IP yang sama tetap bisa login', $r2['ok'] ?? false);
    cek('#4c. aturan password: < 8 karakter & sama dengan username ditolak', cek_password_baru('pendek1') !== null && cek_password_baru('qa_masuk', 'qa_masuk') !== null && cek_password_baru('Panjang#2026') === null);

    // ---------------------------------------------------------------- pembayaran ganda bersamaan
    $t = $tiket('QA 5 AA', 60);
    $mh = curl_multi_init(); $hs = [];
    foreach ([1, 2] as $i) {
        $h = curl_init("$BASE/api/index.php?r=keluar.bayar");
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $tk"], CURLOPT_POSTFIELDS => ['id' => $t['id'], 'metode' => 'cash']]);
        curl_multi_add_handle($mh, $h); $hs[] = $h;
    }
    do { curl_multi_exec($mh, $jalan); curl_multi_select($mh); } while ($jalan);
    $okN = 0; foreach ($hs as $h) { $okN += (json_decode(curl_multi_getcontent($h), true)['ok'] ?? false) ? 1 : 0; curl_multi_remove_handle($mh, $h); }
    cek('N4. dua pembayaran bersamaan untuk tiket yang sama -> hanya 1 tercatat', $okN === 1 && (int)q_val('SELECT COUNT(*) FROM transaksi_keluar WHERE kendaraan_masuk_id = ?', [$t['id']]) === 1);

    // ---------------------------------------------------------------- QR palsu
    $palsu = preg_replace('/\.[0-9a-f]{16}$/', '.0000000000000000', $tiket('QA 6 AA')['kode_qr']);
    $r = api('keluar.scan', $tk, ['kode' => $palsu]);
    cek('N5. QR dengan tanda tangan palsu ditolak', !($r['ok'] ?? true) && ($r['kode'] ?? '') === 'qr_invalid');

    // ---------------------------------------------------------------- #5 sesi per perangkat
    $hp1 = $login('qa_dua_hp')['data']['token'] ?? '';
    $hp2 = $login('qa_dua_hp')['data']['token'] ?? '';
    cek('#5a. login di HP kedua tidak memutus HP pertama', (api('me', $hp1)['ok'] ?? false) && (api('me', $hp2)['ok'] ?? false));
    api('auth.logout', $hp2, []);
    cek('#5b. logout HP kedua hanya memutus HP itu', !(api('me', $hp2)['ok'] ?? true) && (api('me', $hp1)['ok'] ?? false));
    token_cabut_semua(993);
    cek('#5c. cabut semua sesi (reset password/nonaktif) memutus semua HP', !(api('me', $hp1)['ok'] ?? true));

    // ---------------------------------------------------------------- #7 foto lewat URL bertanda tangan
    $jpg = tempnam(sys_get_temp_dir(), 'qa') . '.jpg';
    $im = imagecreatetruecolor(64, 48); imagejpeg($im, $jpg);
    $tf = api('masuk', $tm, ['_form' => ['plat' => 'QA 7 AA', 'jenis' => 'motor', 'paksa' => '1', 'foto' => new CURLFile($jpg, 'image/jpeg', 'f.jpg')]])['data'] ?? [];
    @unlink($jpg);
    $get = function (string $url): int { $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]); curl_exec($c); $k = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); return $k; };
    $host = preg_replace('#^(https?://[^/]+).*$#', '$1', $BASE);
    $path = (string)q_val('SELECT foto_masuk_url FROM kendaraan_masuk WHERE id = ?', [$tf['id'] ?? '']);
    $url = (string)($tf['foto'] ?? '');
    cek('#7a. API memberi URL foto bertanda tangan (foto.php)', strpos($url, 'foto.php?f=') !== false);
    cek('#7b. URL bertanda tangan bisa dibuka', $url !== '' && $get($host . $url) === 200);
    if (!$SERVER_BAWAAN) cek('#7c. akses langsung ke folder uploads/foto ditolak', $path !== '' && $get("$BASE/$path") === 403);
    cek('#7d. tanda tangan diubah ditolak', $get($host . preg_replace('/s=[0-9a-f]{4}/', 's=0000', $url)) === 403);
    $exp = time() - 60;
    cek('#7e. tautan kedaluwarsa ditolak', $get("$BASE/foto.php?f=" . rawurlencode($path) . "&e=$exp&s=" . tanda_foto($path, $exp)) === 410);
    if ($path) @unlink(APP_ROOT . '/' . $path);

    // ---------------------------------------------------------------- #11 #12 kondisi HP (jam & antrean offline)
    $hdr = ['X-App-Versi: v13', 'X-Antrean: 4', 'X-Antrean-Umur: 45', 'X-Jam-HP: ' . (int)round((microtime(true) - 600) * 1000)];
    api('me', $tm, null, $hdr);
    $kond = q_one('SELECT antrean_offline, antrean_sejak, jam_selisih_detik FROM petugas WHERE id = 990');
    cek('#11. jam HP 10 mnt lebih lambat terdeteksi & tercatat', $kond && abs((int)$kond['jam_selisih_detik'] + 600) <= 5, ($kond['jam_selisih_detik'] ?? '?') . ' dtk');
    cek('#12a. antrean offline HP tercatat (jumlah & sejak kapan)', $kond && (int)$kond['antrean_offline'] === 4 && abs(strtotime((string)$kond['antrean_sejak']) - (time() - 2700)) <= 90);
    $judul = array_column(daftar_perhatian(), 'judul');
    cek('#12b. panel Perlu perhatian menampilkan antrean tertahan & jam tidak sinkron',
        (bool)preg_grep('/Antrean offline tertahan di HP QA qa_masuk/', $judul) && (bool)preg_grep('/Jam HP QA qa_masuk tidak sinkron/', $judul));

    // ---------------------------------------------------------------- tindak lanjut selisih setoran
    // Hitung peringatan "kurang setor" (database bisa sudah berisi selisih sungguhan) lalu bandingkan.
    $jmlKurang = function () { foreach (daftar_perhatian() as $p) if (preg_match('/^(\d+) shift kurang setor belum ditindaklanjuti/', $p['judul'], $m)) return (int)$m[1]; return 0; };
    $awal = $jmlKurang();
    q("INSERT INTO shift (petugas_id, pos, pos_nama, waktu_buka, waktu_tutup, total_cash_sistem, total_cash_setor, selisih) VALUES (991, 'keluar', 'QA TL', ?, ?, 50000, 17000, -33000)", [jkt(time() - 7200), jkt(time() - 3600)]);
    $sid = (int)db()->lastInsertId();
    $adaKurang = function () use ($jmlKurang, $awal) { return $jmlKurang() > $awal; };
    cek('TL1. kurang setor muncul di Perlu perhatian', $adaKurang());
    $h = tindak_lanjut_selisih($sid, 'susulan', 13000, 'disetor sebagian', 1);
    cek('TL2. setoran susulan sebagian -> status sebagian, sisa Rp20.000', $h['status'] === 'sebagian' && $h['sisa'] === -20000);
    $tolak = false; try { tindak_lanjut_selisih($sid, 'susulan', 25000, 'lebih', 1); } catch (ParkirException $e) { $tolak = true; }
    cek('TL3. susulan melebihi sisa kekurangan ditolak', $tolak);
    cek('TL4. masih muncul di Perlu perhatian selama belum tuntas', $adaKurang());
    $h = tindak_lanjut_selisih($sid, 'ditanggung', 0, 'potong gaji bulan ini', 1);
    $sh = q_one('SELECT selisih, setor_susulan, selisih_status FROM shift WHERE id = ?', [$sid]);
    cek('TL5. sisa ditanggung petugas -> tuntas; selisih asli tidak diubah (audit)', $h['status'] === 'ditanggung' && (int)$sh['selisih'] === -33000 && (int)$sh['setor_susulan'] === 13000);
    cek('TL6. hilang dari Perlu perhatian setelah tuntas', !$adaKurang());
    $tolak = false; try { tindak_lanjut_selisih($sid, 'dihapuskan', 0, 'lagi', 1); } catch (ParkirException $e) { $tolak = true; }
    cek('TL7. shift yang sudah tuntas tidak bisa ditindaklanjuti ulang', $tolak);
    q("INSERT INTO shift (petugas_id, pos, pos_nama, waktu_buka, waktu_tutup, total_cash_sistem, total_cash_setor, selisih) VALUES (991, 'keluar', 'QA TL', ?, ?, 10000, 15000, 5000)", [jkt(time() - 7200), jkt(time() - 3600)]);
    $sid2 = (int)db()->lastInsertId();
    $tolak = false; try { tindak_lanjut_selisih($sid2, 'susulan', 1000, 'x', 1); } catch (ParkirException $e) { $tolak = true; }
    $h = tindak_lanjut_selisih($sid2, 'dikembalikan', 0, 'dikembalikan ke petugas', 1);
    cek('TL8. lebih setor: susulan ditolak, "dikembalikan" diterima', $tolak && $h['status'] === 'dikembalikan');
    cek('TL9. tindak lanjut tercatat di log aktivitas', (int)q_val("SELECT COUNT(*) FROM log_aktivitas WHERE aksi = 'tindak_lanjut_selisih' AND referensi_id IN (?, ?)", [$sid, $sid2]) === 3);
    q("DELETE FROM log_aktivitas WHERE aksi = 'tindak_lanjut_selisih' AND referensi_id IN (?, ?)", [$sid, $sid2]);

    // ---------------------------------------------------------------- #14 ping
    $p = api('ping');
    cek('#14. endpoint ping (tanpa login) melaporkan sehat', ($p['ok'] ?? false) && ($p['data']['status'] ?? '') === 'sehat' && $p['_http'] === 200);

    // ---------------------------------------------------------------- #9 CSV
    cek('#9. CSV: teks berawalan = + - @ dinetralkan, angka negatif tetap angka', csv_aman('=HYPERLINK("x")') === "'=HYPERLINK(\"x\")" && csv_aman('-1000') === '-1000' && csv_aman(-1000) === -1000 && csv_aman('Shift pagi') === 'Shift pagi');

    // ---------------------------------------------------------------- tutup shift
    $tutupM = api('shift.tutup', $tm, ['catatan' => 'uji']);
    cek('N6. tutup shift pos masuk tanpa setoran', ($tutupM['ok'] ?? false) && $tutupM['data']['shift']['total_cash_setor'] === null);
    $tutupK = api('shift.tutup', $tk, []);
    cek('N7. tutup shift pos keluar tanpa setoran ditolak', !($tutupK['ok'] ?? true) && ($tutupK['kode'] ?? '') === 'setor');
} finally {
    bersihkan();
}

echo $gagal ? "\n$gagal kasus gagal\n" : "\nSemua kasus lulus\n";
exit($gagal ? 1 : 0);
