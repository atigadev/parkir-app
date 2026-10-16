<?php
/**
 * Pembacaan plat lewat API Plate Recognizer (cloud) atau Plate Recognizer SDK
 * yang dipasang lokal (endpoint sama: /v1/plate-reader/). Dipanggil dari server
 * agar token API tidak tersimpan di HP petugas.
 *
 * Hasil OCR hanya pengisi otomatis; petugas selalu mengonfirmasi.
 */
declare(strict_types=1);

function ocr_aktif(): bool
{
    $url = trim((string)setting('ocr_url'));
    if ($url === '') return false;
    if (stripos($url, 'api.platerecognizer.com') !== false && trim((string)setting('ocr_token')) === '') return false;
    return true;
}

/** @return array{plat:string,skor:float,ms:int}|array{error:string,ms:int} */
function ocr_baca(string $pathFoto): array
{
    $mulai = microtime(true);
    if (!ocr_aktif()) return ['error' => 'nonaktif', 'ms' => 0];

    $timeout = max(1, (int)setting('ocr_timeout', '3'));
    $headers = [];
    $token = trim((string)setting('ocr_token'));
    if ($token !== '') $headers[] = 'Authorization: Token ' . $token;

    $ch = curl_init(trim((string)setting('ocr_url')));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => ['upload' => new CURLFile($pathFoto), 'regions' => 'id'],
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ms = (int)round((microtime(true) - $mulai) * 1000);

    if ($body === false) return ['error' => 'timeout: ' . $err, 'ms' => $ms];
    if ($code < 200 || $code >= 300) return ['error' => 'http ' . $code, 'ms' => $ms];
    $json = json_decode((string)$body, true);
    $hasil = $json['results'][0] ?? null;
    if (!$hasil || empty($hasil['plate'])) return ['error' => 'plat tidak terbaca', 'ms' => $ms];

    return ocr_pilih_hasil($hasil, (float)setting('ocr_ambang', '0.9')) + ['ms' => $ms];
}

/**
 * Dari satu hasil Plate Recognizer, pilih kandidat pertama yang sesuai pola plat
 * Indonesia (bila tidak ada: hasil teratas) dan tentukan apakah OCR cukup yakin.
 */
function ocr_pilih_hasil(array $hasil, float $ambang): array
{
    $pilih = ['plate' => $hasil['plate'], 'score' => $hasil['score'] ?? 0];
    $sesuaiPola = false;
    foreach (array_merge([$pilih], $hasil['candidates'] ?? []) as $k) {
        if (pola_plat_valid((string)$k['plate'])) {
            $pilih = $k;
            $sesuaiPola = true;
            break;
        }
    }
    $skor = round((float)($pilih['score'] ?? 0), 2);

    $alasan = [];
    if (!$sesuaiPola) $alasan[] = 'format plat tidak dikenali';
    if ($skor < $ambang) $alasan[] = 'keyakinan rendah (' . round($skor * 100) . '%)';

    return [
        'plat'   => normalisasi_plat((string)$pilih['plate']),
        'skor'   => $skor,
        'yakin'  => !$alasan,
        'alasan' => implode(', ', $alasan),
    ];
}

/** Pola plat Indonesia: 1–2 huruf kode wilayah, 1–4 angka, 0–3 huruf seri. */
function pola_plat_valid(string $plat): bool
{
    return (bool)preg_match('/^[A-Z]{1,2}\d{1,4}[A-Z]{0,3}$/', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plat)));
}
