# ParkIt — Sistem Parkir Plat Nomor & QR

Sistem parkir motor dan mobil berbasis smartphone, sesuai dokumen *Spesifikasi Sistem Parkir Plat Nomor & QR*.
PHP native (tanpa framework) + MySQL/MariaDB, berjalan di XAMPP.

| Bagian | Lokasi | Untuk |
|---|---|---|
| Aplikasi petugas (PWA) | `/parkit-app/pwa/` | HP Android petugas pos masuk & keluar |
| Panel admin | `/parkit-app/admin/` | Dashboard, laporan, tarif, petugas, void, rekonsiliasi QRIS |
| REST API | `/parkit-app/api/index.php?r=…` | Dipakai PWA (token Bearer per petugas) |

## Instalasi

1. Salin folder ke `C:\xampp\htdocs\parkit-app`, jalankan Apache + MySQL.
2. Buat database lalu impor skema:
   ```
   C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE parkit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   C:\xampp\mysql\bin\mysql.exe -u root parkit < database\schema.sql
   ```
   Di cPanel: buat database lewat **MySQL Databases**, lalu impor `schema.sql` di phpMyAdmin.
3. `copy config.sample.php config.php`, lalu isi `qr_secret` dengan string acak:
   `php -r "echo bin2hex(random_bytes(32));"`
   **Jangan ubah `qr_secret` setelah sistem berjalan** — tiket yang sudah tercetak menjadi tidak valid.
4. Buka `http://localhost/parkit-app/admin/`, login **admin / admin123**, lalu **segera ganti password** (menu Petugas → Ubah → Reset password).
5. Di admin: atur **Tarif**, **Pengaturan** (nama lokasi, denda tanpa tiket, gambar QRIS statis, token OCR), dan buat akun **Petugas**.

## HTTPS wajib untuk kamera langsung & printer Bluetooth

Chrome hanya mengizinkan kamera (`getUserMedia`), Web Bluetooth, dan service worker di *secure context*.
Tanpa HTTPS aplikasi tetap jalan dengan cadangan: tombol 📷 (kamera bawaan), "Foto QR", input manual, dan mode cetak RawBT.

Pilih salah satu:

- **Disarankan — sertifikat lokal tepercaya (mkcert)**
  1. `mkcert -install` lalu `mkcert 192.168.1.10` (IP server) di PC server.
  2. Pasang file `.crt`/`.key` hasilnya di `C:\xampp\apache\conf\extra\httpd-ssl.conf` (`SSLCertificateFile`, `SSLCertificateKeyFile`).
  3. Pasang `rootCA.pem` (dari `mkcert -CAROOT`) di tiap HP: *Setelan → Keamanan → Enkripsi & kredensial → Instal sertifikat → Sertifikat CA*.
  4. Petugas membuka `https://192.168.1.10/parkit-app/pwa/` → menu Chrome → **Tambahkan ke layar utama**.
- **Cepat untuk uji coba** — di Chrome HP buka `chrome://flags/#unsafely-treat-insecure-origin-as-secure`, isi `http://192.168.1.10`, aktifkan, lalu restart Chrome.

## Alur kerja petugas

1. Login → pilih **Pos Masuk** atau **Pos Keluar** + nama gerbang → **Buka Shift**.
2. **Pos masuk**: pilih Motor/Mobil → tekan tombol rana → OCR mengisi plat (maks. ±3 detik, selain itu ketik manual) → petugas cocokkan → **Cetak Tiket** → kamera kembali otomatis.
3. **Pos keluar**: arahkan ke QR → layar menampilkan foto masuk, plat, durasi, dan total → **Cash** (bantu hitung kembalian) atau **QRIS** (tampil QRIS statis + nominal; tekan *Sudah Bayar* setelah notifikasi masuk) → resi keluar tercetak. Foto kendaraan keluar diambil otomatis dari kamera scanner.
   - **Tanpa tiket / kendaraan tidak sesuai**: cari plat → verifikasi foto masuk & STNK → catatan wajib → denda otomatis.
4. **Riwayat**: 20 transaksi terakhir di perangkat, cetak ulang, minta void ke admin.
5. **Shift** → isi uang setoran → **Tutup Shift** → selisih tercatat dan tampil di dashboard admin.

## Printer thermal 58 mm

Atur di tab **Shift → Printer**:

- **Bluetooth langsung**: Chrome Android + HTTPS. Tekan *Hubungkan printer* sekali per sesi (chip 🖨 di atas menunjukkan status; ketuk untuk menyambung ulang).
- **RawBT**: pasang aplikasi *RawBT* dari Play Store, pasangkan printer di sana. Cocok bila Web Bluetooth tidak didukung printer/HP.
- **Dialog cetak browser**: cadangan terakhir.

QR dicetak sebagai gambar raster (ESC/POS `GS v 0`) agar didukung printer murah. Resi yang gagal tercetak diberi tanda di tab Riwayat (badge merah) dan bisa dicetak ulang.

## Mode offline

- **Pos masuk**: bila server tidak terjangkau, tiket dibuat di HP (QR bertanda `OFF…`), dicetak, dan disimpan di antrean IndexedDB.
- **Pos keluar**: tarif dihitung di HP dengan salinan tarif terakhir (`pwa/js/tarif.js`, identik dengan `inc/tarif.php`). Plat khusus tidak bisa dicek saat offline; petugas mengetik plat dari kendaraan.
- Antrean dikirim otomatis tiap 20 detik saat online (chip "⇅ n antre"). Sinkronisasi idempoten; item yang ditolak server (mis. QR palsu) muncul di tab Shift dan tercatat di **Log Aktivitas** (`sync_ditolak`).
- Pencarian plat (tanpa tiket) dan penutupan shift membutuhkan koneksi.

## OCR plat (Plate Recognizer)

Pengaturan → OCR: isi token dari platerecognizer.com (cloud), atau arahkan URL ke Plate Recognizer SDK lokal (`http://127.0.0.1:8080/v1/plate-reader/`). OCR dipanggil dari server sehingga token tidak tersimpan di HP. Akurasinya dipantau di menu **Kualitas OCR** (persentase plat yang dikoreksi petugas).

## Aturan tarif

Diterapkan di `inc/tarif.php` (dan port JS-nya):

1. Versi tarif = versi aktif terbaru dengan `berlaku_mulai ≤ waktu_masuk` (tarif lama tidak pernah dihapus).
2. Plat khusus (member/dinas/gratis) yang berlaku → Rp0.
3. Durasi efektif = keluar − masuk − toleransi; ≤ 0 → gratis.
4. Dipecah per hari kalender: ≤ durasi awal → biaya awal; selebihnya + ⌈jam sisa⌉ × biaya per jam; dibatasi maksimal per hari.
5. \+ biaya menginap setiap kali jam batas menginap terlewati.
6. \+ denda bila keluar tanpa tiket.

Rincian lengkap beserta salinan tarif disimpan di `transaksi_keluar.rincian_biaya` (JSON), sehingga total tetap bisa dijelaskan setelah tarif berubah. Admin bisa mencoba skenario di **Tarif → Simulasi**.

> Catatan: karena dihitung per hari kalender, kendaraan yang masuk 23:00 dan keluar 01:00 dikenai biaya awal dua kali ditambah satu malam menginap. Ini mengikuti spesifikasi; bila terasa terlalu mahal, set biaya menginap 0 atau naikkan jam batas menginap.

## Keamanan & anti-kecurangan

- QR berisi token HMAC (`PK1.<uuid>.<jenis>.<waktu>.<tanda>`), bukan plat; server mencocokkan persis dengan database. Tiket hanya bisa dipakai keluar sekali.
- Tidak ada penghapusan transaksi. Petugas hanya bisa *meminta* void; admin menyetujui dengan alasan wajib. Semua koreksi plat, void, perubahan tarif/pengaturan/petugas, login, dan keluar tanpa tiket tercatat di **Log Aktivitas**.
- Rekap cash per shift dihitung sistem; selisih setoran tampil di dashboard dan laporan.
- `inc/`, `database/`, `cli/`, `tests/`, `backup/`, `config.php`, folder tersembunyi (`.git`, `.env`), serta arsip/dump/log (`*.zip`, `*.sql`, `*.bak`, `*.log`, …) diblokir dari web lewat `.htaccess`; folder `uploads/` tidak bisa mengeksekusi skrip.
- Jangan menaruh arsip deploy (mis. `.zip` berisi `config.php`) di folder situs. Arsip dan `config.php` juga diabaikan Git (`.gitignore`).
- Batasi API ke LAN: di `config.php` isi `'api_allow_prefix' => ['127.0.0.1', '::1', '192.168.']`.
- XAMPP: beri password root MySQL (lalu sesuaikan `config.php` dan `cli/backup.bat`), batasi phpMyAdmin ke localhost.

## Tugas terjadwal (Windows Task Scheduler)

| Tugas | Perintah | Jadwal |
|---|---|---|
| Backup database | `C:\xampp\htdocs\parkit-app\cli\backup.bat` | Harian, di luar jam ramai |
| Retensi foto | `C:\xampp\php\php.exe C:\xampp\htdocs\parkit-app\cli\maintenance.php` | Harian |

Ubah `TUJUAN` di `backup.bat` ke disk lain atau NAS. Saran operasional lain (sesuai spesifikasi): UPS untuk server dan access point, matikan update otomatis Windows di jam operasional, `innodb_buffer_pool_size` 1–2 GB.

## Update & migrasi database

Migrasi skema bersifat **idempoten**, artinya aman dijalankan berulang kali. Kolom dan pengaturan hanya ditambahkan bila belum ada, dan nilai yang sudah diatur admin tidak pernah ditimpa.

- **Otomatis:** setelah `git pull` atau upload file, permintaan web pertama menerapkan migrasi yang belum dijalankan (`inc/migrasi.php`). Tidak perlu menjalankan SQL manual. Prosesnya dikunci, sehingga dua permintaan bersamaan tetap aman.
- **Manual lewat terminal:** `php cli/migrasi.php`. Tambahkan `--periksa` untuk memeriksa dan memperbaiki semua langkah, misalnya setelah memulihkan backup lama.
- **Manual lewat phpMyAdmin:** impor `database/migrasi.sql`. Gunakan cara ini bila akun database aplikasi tidak punya hak `ALTER`. Kompatibel dengan MySQL dan MariaDB.
- **Instalasi baru:** cukup `database/schema.sql`, yang juga aman diimpor ulang.

Versi skema tersimpan di `pengaturan.skema_versi`. Untuk migrasi baru, tambahkan langkah di `langkah_migrasi()`, naikkan `SKEMA_VERSI`, tambahkan SQL yang setara di `migrasi.sql`, dan tulis struktur akhirnya di `schema.sql`.

## Rilis aplikasi petugas (PWA)

1. Naikkan `PARKIT_VERSI` dan `PARKIT_TANGGAL` di `pwa/versi.js`. Ini satu-satunya sumber nomor versi.
2. Samakan query `?v=` di `pwa/index.html` dan `pwa/sw.js` dengan versi baru.
3. Setelah server diperbarui, HP petugas mendeteksi versi baru dalam beberapa detik saat aplikasi dibuka, atau paling lambat 10 menit.
   - Di layar kerja muncul banner **"Versi baru tersedia — Muat ulang"**.
   - Di layar login atau pilih pos, aplikasi langsung memuat versi baru.
4. Versi yang sedang dipakai tiap HP terlihat di **Admin → Petugas** dan di tabel **Shift berjalan** pada dashboard. Versi lama ditandai *perlu update*.

## Pengujian

```
php tests/tarif_test.php
php tests/ocr_test.php
php tests/migrasi_test.php
```

`migrasi_test.php` memakai database uji sementara (`parkit_ujimigrasi`) yang dibuat lalu dihapus otomatis, jadi database aplikasi tidak tersentuh.

Perintah di atas menguji mesin tarif PHP dan membuat `tests/tarif_cases.js`. Versi JS diuji dengan membuka `tests/tarif_parity.html` langsung dari disk (`file:///…`), atau secara headless:

```
chrome --headless --allow-file-access-from-files --dump-dom file:///C:/xampp/htdocs/parkit-app/tests/tarif_parity.html
```

## Struktur

```
api/index.php        REST API untuk PWA
admin/               Panel admin (PHP + CSS)
inc/                 bootstrap, helper, mesin tarif, token QR, OCR, operasi parkir
pwa/                 Aplikasi petugas: index.html, app.css, js/, sw.js, manifest
database/schema.sql  Skema + data awal
cli/                 Backup & retensi foto
tests/               Uji mesin tarif (PHP & JS)
uploads/             Foto kendaraan & gambar QRIS
```

## Roadmap (dari spesifikasi)

Tahap 1–4 sudah diimplementasikan: inti, OCR, ketahanan (offline, cetak ulang, tanpa tiket, plat khusus), dan admin lengkap (laporan, void dengan approval, log, rekonsiliasi QRIS).
Tahap 5 (QRIS dinamis via Midtrans/Xendit dengan callback) belum dikerjakan. Titik integrasinya di `keluar.bayar` pada `api/index.php`.
