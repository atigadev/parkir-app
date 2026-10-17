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

## Printer thermal (56/58 mm & 80 mm)

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

**Pendapatan**
- QR berisi token HMAC (`PK1.<uuid>.<jenis>.<waktu>.<tanda>`), bukan plat. Server mencocokkan dengan database, dan satu tiket hanya bisa dipakai keluar sekali.
- **Waktu keluar ditentukan server.** Saat scan, server memberi *token hitung* bertanda tangan yang berlaku 5 menit. Waktu keluar dari HP tanpa token sah diabaikan, sehingga tidak bisa dimundurkan untuk memperkecil tarif.
- **Transaksi offline selalu dihitung ulang server.** Nominal yang dicatat HP disimpan terpisah (`total_offline`), sedangkan setoran shift yang diharapkan mengikuti hitungan server. Manipulasi nominal di HP terlihat sebagai kekurangan setoran dan muncul di **Perlu perhatian** serta filter **Offline berselisih**.
- **Waktu masuk offline yang janggal** (di masa depan atau sebelum shift dibuka) dikoreksi otomatis dan ditandai.
- Tidak ada penghapusan transaksi. Petugas hanya bisa *meminta* void, lalu admin menyetujui dengan alasan wajib. Semuanya tercatat di **Log Aktivitas**.

**Akun & sesi**
- Login dikunci 15 menit setelah 5 kali gagal per akun, atau 20 kali per alamat IP.
- Password minimal 8 karakter dan bukan password umum. Akun dengan password bawaan `admin123` **wajib mengganti password** sebelum bisa memakai panel admin (menu *Akun saya*).
- Sesi aplikasi petugas berlaku per perangkat (maks 5). Logout hanya memutus perangkat itu, sedangkan ganti/reset password dan nonaktifkan akun memutus semua perangkat. Sesi admin berakhir setelah 4 jam tidak ada aktivitas.

**Server & data**
- Header keamanan: CSP (admin memakai *nonce*, PWA hanya skrip dari server sendiri), HSTS di HTTPS, `X-Frame-Options`, dan `Referrer-Policy`. Cookie sesi memakai `Secure` di HTTPS, dan `X-Powered-By` dihapus.
- `display_errors` mati kecuali `'debug' => true` di `config.php`. Jangan aktifkan `debug` di produksi.
- **Foto kendaraan** (data pribadi) hanya bisa dibuka lewat `foto.php` dengan URL bertanda tangan yang kedaluwarsa dalam 2 jam. Folder `uploads/foto` tertutup dari akses langsung.
- `inc/`, `database/`, `cli/`, `tests/`, `backup/`, `config.php`, folder tersembunyi (`.git`, `.env`), serta arsip/dump/log (`*.zip`, `*.sql`, `*.bak`, `*.log`, …) diblokir lewat `.htaccess`. Folder `uploads/` tidak bisa mengeksekusi skrip.
- Ekspor CSV menetralkan teks yang diawali `= + - @` (mencegah *formula injection* di Excel).
- **Hosting Nginx** tidak membaca `.htaccess`. Terapkan aturan blokir yang sama (terutama `uploads/foto`, `inc/`, `config.php`, `*.sql`) di konfigurasi Nginx.
- Untuk menyembunyikan versi Apache, set `ServerTokens Prod` dan `ServerSignature Off` di konfigurasi Apache. Ini tidak bisa diatur lewat `.htaccess`.
- Jangan menaruh arsip deploy (misalnya `.zip` berisi `config.php`) di folder situs.
- Batasi API ke LAN bila server lokal: `'api_allow_prefix' => ['127.0.0.1', '::1', '192.168.']`. Di hosting biarkan `[]`.
- XAMPP: beri password root MySQL (lalu sesuaikan `config.php`), dan batasi phpMyAdmin ke localhost.

## Pemantauan

- **Admin → Dashboard → Perlu perhatian** mengumpulkan semua hal yang perlu ditindaklanjuti:
  - transaksi offline berselisih, shift kurang setor, tiket offline berjam janggal
  - permintaan void, QRIS hari sebelumnya yang belum direkonsiliasi
  - transaksi offline yang ditolak server, antrean offline yang tertahan di HP, jam HP yang tidak sinkron, HP dengan aplikasi versi lama
  - login gagal berulang, shift lupa ditutup
  - password admin bawaan, backup yang terlambat, migrasi yang belum lengkap
- **Uptime monitor:** arahkan layanan seperti UptimeRobot ke `https://DOMAIN/api/index.php?r=ping`. Respons HTTP 200 berarti aplikasi dan database sehat, sedangkan 503 berarti database tidak terjangkau.
- **Ringkasan harian lewat email:** isi *Pengaturan → Email ringkasan harian*, lalu jadwalkan `cli/ringkasan_harian.php` setiap pagi. Isinya pendapatan kemarin ditambah daftar Perlu perhatian. Uji tampilannya dengan `php cli/ringkasan_harian.php --cetak`.
- **Aplikasi petugas** memperingatkan petugas bila jam HP selisih lebih dari 2 menit dengan server, atau bila ada transaksi offline yang belum terkirim lebih dari 15 menit.

## SOP rekonsiliasi QRIS (harian)

QRIS statis tidak memberi konfirmasi otomatis, sehingga kebenaran "Sudah Bayar" dari petugas dipastikan lewat rekonsiliasi:

1. Setiap pagi buka **Admin → Rekonsiliasi QRIS**, lalu pilih tanggal kemarin.
2. Cocokkan setiap transaksi dengan mutasi rekening atau aplikasi merchant (nominal dan jam).
3. Centang yang cocok, lalu klik **Tandai cocok**.
4. Transaksi yang **tidak ada di mutasi**: buka detail transaksinya, cek foto dan petugasnya, lalu tindak lanjuti dengan petugas tersebut.
5. Tanggal yang belum selesai direkonsiliasi akan terus muncul di **Perlu perhatian**.

Bila volume QRIS besar, pertimbangkan QRIS dinamis lewat payment gateway (roadmap tahap 5).

## Tugas terjadwal

| Tugas | cPanel (Cron Jobs) | Windows (Task Scheduler) | Jadwal |
|---|---|---|---|
| Backup database | `php /home/USER/parkit/cli/backup.php` | `C:\xampp\htdocs\parkit-app\cli\backup.bat` | Harian, di luar jam ramai |
| Retensi foto | `php /home/USER/parkit/cli/maintenance.php` | `C:\xampp\php\php.exe ...\cli\maintenance.php` | Harian |
| Ringkasan email | `php /home/USER/parkit/cli/ringkasan_harian.php` | `C:\xampp\php\php.exe ...\cli\ringkasan_harian.php` | Harian 06:00 |

**Backup** (`cli/backup.php`) memakai PHP murni (tanpa `mysqldump`), menghasilkan file `.sql.gz`, dan menyimpan 14 backup terakhir.
- Atur folder lewat `'backup_dir'` dan jumlah simpanan lewat `'backup_simpan'` di `config.php`, atau beri argumen `--ke=/path`. **Simpan backup di luar folder situs**, dan salin berkala ke tempat lain (Google Drive, komputer kantor).
- **Pulihkan:** `gunzip -c parkit-XXXX.sql.gz | mysql -u USER -p NAMA_DB`, atau phpMyAdmin → Import file `.sql.gz`. Setelah itu jalankan `php cli/migrasi.php --periksa`.
- `tests/backup_test.php` membuktikan backup bisa dipulihkan: backup dibuat, dipulihkan ke database uji, lalu isi setiap tabel dibandingkan.

Saran operasional lain (sesuai spesifikasi): UPS untuk server dan access point, matikan update otomatis Windows di jam operasional, `innodb_buffer_pool_size` 1–2 GB.
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

Jalankan **sebelum setiap deploy**, sebaiknya di server *staging* atau komputer lokal (bukan produksi, karena uji API membuat dan menghapus data sementara):

```
php tests/jalankan_semua.php                      # memakai http://localhost/parkit-app
php tests/jalankan_semua.php http://staging.contoh.com --yakin
```

| Uji | Isi |
|---|---|
| `tarif_test.php` | Mesin tarif PHP (13 kasus). Juga membuat `tests/tarif_cases.js` untuk uji versi JS. |
| `ocr_test.php` | Pemilihan hasil OCR dan peringatan "OCR ragu". |
| `migrasi_test.php` | Idempotensi migrasi skema di database uji sementara. |
| `backup_test.php` | Backup lalu **pemulihan** ke database uji, dengan isi tabel dibandingkan. |
| `api_test.php` | Alur API ujung-ke-ujung dan skenario kecurangan/serangan dari laporan QA (35+ kasus). |

Database uji sementara (`parkit_ujimigrasi`, `parkit_ujibackup`) dibuat lalu dihapus otomatis, dan akun uji (id 990–993) dibersihkan.

**Kompatibilitas PHP:** semua uji lulus di **PHP 7.4 dan PHP 8.2**, tanpa warning maupun deprecation (`error_reporting=-1`). Untuk menguji dengan server bawaan PHP, yang tidak membaca `.htaccess`:

```
php -S 127.0.0.1:8098 -t C:/xampp/htdocs
php tests/jalankan_semua.php http://127.0.0.1:8098/parkit-app --server-bawaan
```

Versi JS mesin tarif diuji dengan membuka `tests/tarif_parity.html` langsung dari disk (`file:///…`), atau secara headless:

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
cli/                 Backup, retensi foto, migrasi, ringkasan harian
tests/               Uji otomatis (jalankan_semua.php)
foto.php             Penyaji foto kendaraan dengan URL bertanda tangan
uploads/             Foto kendaraan & gambar QRIS
```

## Roadmap (dari spesifikasi)

Tahap 1–4 sudah diimplementasikan: inti, OCR, ketahanan (offline, cetak ulang, tanpa tiket, plat khusus), dan admin lengkap (laporan, void dengan approval, log, rekonsiliasi QRIS).
Tahap 5 (QRIS dinamis via Midtrans/Xendit dengan callback) belum dikerjakan. Titik integrasinya di `keluar.bayar` pada `api/index.php`.
