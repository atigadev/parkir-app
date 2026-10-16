<?php
/**
 * Mesin tarif parkir. Logika ini juga di-port ke pwa/js/tarif.js (dipakai saat
 * pos keluar offline). Jika aturan di sini berubah, ubah juga versi JS-nya dan
 * jalankan tests/tarif_test.php + tests/tarif_parity.html.
 *
 * Aturan:
 *  1. Tarif = versi aktif terbaru dengan berlaku_mulai <= waktu_masuk.
 *  2. Plat khusus (member/dinas/gratis) yang masih berlaku -> biaya 0.
 *  3. Durasi efektif = keluar - masuk - toleransi. <= 0 -> gratis.
 *  4. Durasi efektif dipecah per hari kalender. Per hari:
 *       menit <= durasi_awal -> biaya_awal
 *       selain itu biaya_awal + ceil((menit - durasi_awal)/60) * biaya_per_jam
 *     lalu dibatasi maks_per_hari (0 = tanpa batas).
 *  5. Tiap kali jam_batas_menginap terlewati selama parkir -> + biaya_menginap.
 *  6. Total = jumlah per hari + menginap + denda.
 */
declare(strict_types=1);

function tarif_aktif(string $jenis, string $waktuMasuk): ?array
{
    return q_one(
        'SELECT * FROM tarif WHERE jenis_kendaraan = ? AND aktif = 1 AND berlaku_mulai <= ?
         ORDER BY berlaku_mulai DESC, id DESC LIMIT 1',
        [$jenis, $waktuMasuk]
    );
}

function plat_khusus_berlaku(string $plat, string $tanggal): ?array
{
    return q_one(
        'SELECT * FROM plat_khusus WHERE plat_nomor = ? AND aktif = 1
         AND (berlaku_sampai IS NULL OR berlaku_sampai >= ?) ORDER BY id DESC LIMIT 1',
        [$plat, substr($tanggal, 0, 10)]
    );
}

/**
 * Hitung biaya. Fungsi murni: tidak menyentuh database.
 *
 * @param array       $tarif   baris tabel tarif
 * @param string      $masuk   'Y-m-d H:i:s'
 * @param string      $keluar  'Y-m-d H:i:s'
 * @param array|null  $khusus  baris plat_khusus bila plat terdaftar
 * @param int         $denda   denda tanpa tiket
 */
function hitung_tarif(array $tarif, string $masuk, string $keluar, ?array $khusus = null, int $denda = 0): array
{
    $tMasuk  = new DateTimeImmutable($masuk);
    $tKeluar = new DateTimeImmutable($keluar);
    if ($tKeluar < $tMasuk) $tKeluar = $tMasuk;

    $durasi    = intdiv($tKeluar->getTimestamp() - $tMasuk->getTimestamp(), 60);
    $toleransi = (int)$tarif['toleransi_menit'];
    $efektif   = max(0, $durasi - $toleransi);

    $hasil = [
        'tarif_id'       => (int)$tarif['id'],
        'jenis'          => $tarif['jenis_kendaraan'],
        'tarif'          => [
            'durasi_awal_menit'  => (int)$tarif['durasi_awal_menit'],
            'biaya_awal'         => (int)$tarif['biaya_awal'],
            'biaya_per_jam'      => (int)$tarif['biaya_per_jam'],
            'maks_per_hari'      => (int)$tarif['maks_per_hari'],
            'biaya_menginap'     => (int)$tarif['biaya_menginap'],
            'jam_batas_menginap' => substr((string)$tarif['jam_batas_menginap'], 0, 5),
            'toleransi_menit'    => $toleransi,
        ],
        'waktu_masuk'    => $tMasuk->format('Y-m-d H:i:s'),
        'waktu_keluar'   => $tKeluar->format('Y-m-d H:i:s'),
        'durasi_menit'   => $durasi,
        'durasi_efektif' => $efektif,
        'khusus'         => null,
        'hari'           => [],
        'malam'          => 0,
        'biaya_menginap' => 0,
        'subtotal'       => 0,
        'denda'          => $denda,
        'total'          => $denda,
    ];

    if ($khusus) {
        $hasil['khusus'] = ['tipe' => $khusus['tipe'], 'keterangan' => $khusus['keterangan'] ?? null];
        return $hasil;
    }
    if ($efektif <= 0) {
        return $hasil;
    }

    // 4. Pecah per hari kalender, mulai setelah toleransi.
    $awal    = (int)$tarif['durasi_awal_menit'];
    $bAwal   = (int)$tarif['biaya_awal'];
    $perJam  = (int)$tarif['biaya_per_jam'];
    $maks    = (int)$tarif['maks_per_hari'];
    $cursor  = $tMasuk->modify("+{$toleransi} minutes");
    $subtotal = 0;
    while ($cursor < $tKeluar) {
        $tengahMalam = $cursor->setTime(0, 0)->modify('+1 day');
        $akhir = $tengahMalam < $tKeluar ? $tengahMalam : $tKeluar;
        $menit = intdiv($akhir->getTimestamp() - $cursor->getTimestamp(), 60);
        if ($menit > 0) {
            $jamTambahan = $menit > $awal ? (int)ceil(($menit - $awal) / 60) : 0;
            $biaya = $bAwal + $jamTambahan * $perJam;
            $kenaMaks = $maks > 0 && $biaya > $maks;
            if ($kenaMaks) $biaya = $maks;
            $hasil['hari'][] = [
                'tanggal'      => $cursor->format('Y-m-d'),
                'menit'        => $menit,
                'jam_tambahan' => $jamTambahan,
                'biaya'        => $biaya,
                'kena_maks'    => $kenaMaks,
            ];
            $subtotal += $biaya;
        }
        $cursor = $akhir;
    }

    // 5. Hitung berapa kali jam batas menginap terlewati (masuk < T <= keluar).
    $malam = 0;
    if ((int)$tarif['biaya_menginap'] > 0) {
        [$hh, $mm] = array_map('intval', explode(':', (string)$tarif['jam_batas_menginap']));
        $t = $tMasuk->setTime($hh, $mm);
        if ($t <= $tMasuk) $t = $t->modify('+1 day');
        while ($t <= $tKeluar) {
            $malam++;
            $t = $t->modify('+1 day');
        }
    }

    $hasil['malam'] = $malam;
    $hasil['biaya_menginap'] = $malam * (int)$tarif['biaya_menginap'];
    $hasil['subtotal'] = $subtotal;
    $hasil['total'] = $subtotal + $hasil['biaya_menginap'] + $denda;
    return $hasil;
}

/** Baris-baris rincian siap tampil/cetak. */
function rincian_baris(array $r): array
{
    $baris = [];
    if (!empty($r['khusus'])) {
        $baris[] = ['Plat khusus (' . $r['khusus']['tipe'] . ')', 0];
    } elseif ($r['durasi_efektif'] <= 0) {
        $baris[] = ['Dalam toleransi ' . $r['tarif']['toleransi_menit'] . ' mnt', 0];
    }
    $multi = count($r['hari']) > 1;
    foreach ($r['hari'] as $h) {
        $label = $multi ? date('d/m', strtotime($h['tanggal'])) . ' ' : '';
        $label .= durasi_teks($h['menit']) . ($h['kena_maks'] ? ' (maks)' : '');
        $baris[] = [$label, $h['biaya']];
    }
    if ($r['malam'] > 0) $baris[] = ['Menginap x' . $r['malam'], $r['biaya_menginap']];
    if ($r['denda'] > 0) $baris[] = ['Denda tanpa tiket', $r['denda']];
    return $baris;
}
