/**
 * Cetak resi ke printer thermal 58 mm (32 karakter, 384 dot).
 *  - bluetooth : Web Bluetooth + ESC/POS (Chrome Android, butuh HTTPS)
 *  - rawbt     : kirim ESC/POS ke aplikasi RawBT (Android) lewat intent
 *  - browser   : dialog cetak browser (cadangan)
 * QR dicetak sebagai gambar raster (GS v 0) agar didukung printer murah.
 *
 * Opsi "cetak sebagai gambar" (bawaan: aktif) mengirim seluruh tiket sebagai satu gambar,
 * sehingga teks tercetak di printer yang mengabaikan perintah teks/ukuran huruf ESC/POS.
 */
(function (global) {
  'use strict';

  var LEBAR = 32;
  var SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000fee7-0000-1000-8000-00805f9b34fb'
  ];

  // ---------------------------------------------------------------- ESC/POS
  function Builder() { this.b = []; this.push([0x1b, 0x40]); }
  Builder.prototype.push = function (arr) { for (var i = 0; i < arr.length; i++) this.b.push(arr[i]); return this; };
  Builder.prototype.text = function (s) {
    s = String(s).normalize ? String(s).normalize('NFKD') : String(s);
    for (var i = 0; i < s.length; i++) {
      var c = s.charCodeAt(i);
      if (c === 10 || (c >= 32 && c < 127)) this.b.push(c);
      else if (c >= 0x300 && c < 0x370) continue; // tanda diakritik
      else this.b.push(63);
    }
    return this;
  };
  Builder.prototype.line = function (s) { return this.text((s || '') + '\n'); };
  Builder.prototype.align = function (n) { return this.push([0x1b, 0x61, n]); };      // 0 kiri 1 tengah 2 kanan
  Builder.prototype.bold = function (on) { return this.push([0x1b, 0x45, on ? 1 : 0]); };
  Builder.prototype.size = function (w, h) { return this.push([0x1d, 0x21, ((w - 1) << 4) | (h - 1)]); };
  Builder.prototype.feed = function (n) { return this.push([0x1b, 0x64, n || 1]); };
  Builder.prototype.cut = function () { return this.push([0x1d, 0x56, 0x42, 0x00]); };
  Builder.prototype.rule = function (ch) { return this.line(new Array(LEBAR + 1).join(ch || '-')); };
  Builder.prototype.pair = function (kiri, kanan) {
    kiri = String(kiri); kanan = String(kanan);
    var spasi = LEBAR - kiri.length - kanan.length;
    if (spasi < 1) { this.line(kiri); return this.line(pad('', LEBAR - kanan.length) + kanan); }
    return this.line(kiri + pad('', spasi) + kanan);
  };
  Builder.prototype.qr = function (data, dotsPerModule) {
    var q = qrcode(0, 'M');
    q.addData(data);
    q.make();
    var n = q.getModuleCount(), quiet = 2, scale = dotsPerModule || Math.max(2, Math.min(8, Math.floor(240 / (n + quiet * 2))));
    var size = (n + quiet * 2) * scale;
    var bytesPerRow = Math.ceil(size / 8);
    this.align(1).push([0x1d, 0x76, 0x30, 0x00, bytesPerRow & 0xff, bytesPerRow >> 8, size & 0xff, size >> 8]);
    for (var y = 0; y < size; y++) {
      var my = Math.floor(y / scale) - quiet;
      for (var bx = 0; bx < bytesPerRow; bx++) {
        var byte = 0;
        for (var bit = 0; bit < 8; bit++) {
          var x = bx * 8 + bit, mx = Math.floor(x / scale) - quiet;
          if (x < size && mx >= 0 && my >= 0 && mx < n && my < n && q.isDark(my, mx)) byte |= 0x80 >> bit;
        }
        this.b.push(byte);
      }
    }
    return this.push([0x0a]);
  };
  Builder.prototype.bytes = function () { return new Uint8Array(this.b); };

  function pad(s, n) { while (s.length < n) s += ' '; return s; }

  function bungkus(s, lebar) { return Resi.bungkus(s, lebar); }

  /** Ubah canvas jadi perintah raster ESC/POS (GS v 0), dikirim per blok 96 baris agar buffer printer tidak penuh. */
  function rasterDariCanvas(c) {
    var w = c.width, h = c.height, bpr = Math.ceil(w / 8);
    var px = c.getContext('2d').getImageData(0, 0, w, h).data;
    var b = new Builder().align(0);
    for (var y0 = 0; y0 < h; y0 += 96) {
      var bh = Math.min(96, h - y0);
      b.push([0x1d, 0x76, 0x30, 0x00, bpr & 0xff, bpr >> 8, bh & 0xff, bh >> 8]);
      for (var y = y0; y < y0 + bh; y++) {
        for (var bx = 0; bx < bpr; bx++) {
          var byte = 0;
          for (var bit = 0; bit < 8; bit++) {
            var x = bx * 8 + bit;
            if (x < w) {
              var i = (y * w + x) * 4;
              if (px[i] + px[i + 1] + px[i + 2] < 540) byte |= 0x80 >> bit; // abu-abu gelap ikut hitam agar huruf tidak putus
            }
          }
          b.b.push(byte);
        }
      }
    }
    return b.feed(4).cut().bytes();
  }

  /** Render daftar baris dari Resi.masuk/keluar ke byte ESC/POS. */
  function escpos(rows) {
    var b = new Builder(), LBL = 10;
    rows.forEach(function (r) {
      switch (r[0]) {
        case 'judul':
          b.align(1).bold(true).size(1, 2);
          bungkus(r[1], LEBAR).forEach(function (x) { b.line(x); });
          b.size(1, 1).bold(false); break;
        case 'tengah': case 'kecil':
          b.align(1); bungkus(r[1], LEBAR).forEach(function (x) { b.line(x); }); break;
        case 'tebal':
          b.align(1).bold(true); bungkus(r[1], LEBAR).forEach(function (x) { b.line(x); }); b.bold(false); break;
        case 'besar':
          b.align(1).bold(true).size(2, 2); bungkus(r[1], LEBAR / 2).forEach(function (x) { b.line(x); }); b.size(1, 1).bold(false); break;
        case 'kv':
          b.align(0);
          bungkus(r[2], LEBAR - LBL - 2).forEach(function (x, i) { b.line((i ? pad('', LBL) : pad(String(r[1]), LBL)) + (i ? '  ' : ': ') + x); });
          break;
        case 'lr': b.align(0).pair(String(r[1]).slice(0, LEBAR - String(r[2]).length - 1), r[2]); break;
        case 'total': b.align(0).bold(true).size(1, 2).pair(r[1], r[2]).size(1, 1).bold(false); break;
        case 'garis': b.align(0).rule('-'); break;
        case 'garis2': b.align(0).rule('='); break;
        case 'qr': b.qr(r[1]); break;
        case 'poin':
          b.align(0); bungkus(r[1], LEBAR - 2).forEach(function (x, i) { b.line((i ? '  ' : '- ') + x); }); break;
      }
    });
    return b.feed(4).cut().bytes();
  }

  // ---------------------------------------------------------------- Bluetooth
  var dev = null, chr = null, listeners = [];

  function status() {
    return { mode: mode(), connected: !!(chr && dev && dev.gatt && dev.gatt.connected), name: dev ? dev.name : null };
  }
  function notify() { listeners.forEach(function (f) { try { f(status()); } catch (e) {} }); }
  function mode() { try { return localStorage.getItem('printer_mode') || (navigator.bluetooth ? 'bluetooth' : 'browser'); } catch (e) { return 'browser'; } }
  function setMode(m) { try { localStorage.setItem('printer_mode', m); } catch (e) {} notify(); }
  function opsi(k, bawaan) { try { var v = localStorage.getItem(k); return v === null ? bawaan : v; } catch (e) { return bawaan; } }
  function setOpsi(k, v) { try { localStorage.setItem(k, String(v)); } catch (e) {} }
  /** Cetak seluruh tiket sebagai gambar (bawaan: ya). */
  // Bawaan: gambar untuk RawBT (Bluetooth klasik, cepat); teks untuk BLE (gambar ±40 KB terlalu lambat lewat paket 20 byte).
  function sebagaiGambar() { return opsi('printer_gambar', mode() === 'rawbt' ? '1' : '0') === '1'; }
  /** Ukuran paket Bluetooth (byte). 20 = aman untuk semua printer BLE. */
  function ukuranPaket() { var n = parseInt(opsi('printer_paket', '20'), 10); return n > 0 ? n : 20; }

  function cariKarakteristik(server) {
    return server.getPrimaryServices().then(function (services) {
      var i = 0;
      function next() {
        if (i >= services.length) throw new Error('Karakteristik tulis tidak ditemukan pada printer');
        var s = services[i++];
        return s.getCharacteristics().then(function (cs) {
          for (var j = 0; j < cs.length; j++) {
            if (cs[j].properties.writeWithoutResponse || cs[j].properties.write) return cs[j];
          }
          return next();
        }, next);
      }
      return next();
    });
  }

  function hubungkan(device) {
    dev = device;
    dev.removeEventListener('gattserverdisconnected', onPutus);
    dev.addEventListener('gattserverdisconnected', onPutus);
    return dev.gatt.connect().then(cariKarakteristik).then(function (c) { chr = c; notify(); return status(); });
  }
  function onPutus() { chr = null; notify(); }

  /** Pilih printer (wajib dari gesture pengguna). */
  function pilih() {
    if (!navigator.bluetooth) return Promise.reject(new Error('Browser tidak mendukung Web Bluetooth. Gunakan Chrome Android dengan HTTPS, atau mode RawBT.'));
    return navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: SERVICES }).then(hubungkan);
  }

  /** Sambung ulang otomatis ke printer yang pernah diizinkan (bila browser mendukung). */
  function sambungUlang() {
    if (chr && dev && dev.gatt.connected) return Promise.resolve(status());
    if (dev) return hubungkan(dev);
    if (navigator.bluetooth && navigator.bluetooth.getDevices) {
      return navigator.bluetooth.getDevices().then(function (ds) {
        if (!ds.length) throw new Error('Printer belum dipilih');
        return hubungkan(ds[0]);
      });
    }
    return Promise.reject(new Error('Printer belum terhubung'));
  }

  function tulisBT(data) {
    return sambungUlang().then(function () {
      // Printer BLE murah umumnya hanya menerima 20 byte per paket; paket lebih besar terpotong diam-diam
      // (sebagian teks hilang). Jeda singkat tiap ±1 KB memberi waktu buffer printer mengosongkan diri.
      var off = 0, CHUNK = ukuranPaket(), tanpaRespons = chr.properties.writeWithoutResponse && chr.writeValueWithoutResponse;
      function step() {
        if (off >= data.length) return Promise.resolve();
        var part = data.slice(off, off + CHUNK);
        off += CHUNK;
        var p = tanpaRespons ? chr.writeValueWithoutResponse(part) : chr.writeValue(part);
        var jeda = off % 1024 < CHUNK ? 40 : (tanpaRespons ? 4 : 0);
        return p.then(function () { return jeda ? new Promise(function (r) { setTimeout(r, jeda); }) : null; }).then(step);
      }
      return step();
    });
  }

  // ---------------------------------------------------------------- RawBT & browser
  function keBase64(u8) {
    var s = '';
    for (var i = 0; i < u8.length; i += 0x8000) s += String.fromCharCode.apply(null, u8.subarray(i, i + 0x8000));
    return btoa(s);
  }
  function tulisRawBT(data) {
    location.href = 'intent:base64,' + keBase64(data) + '#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;';
    return Promise.resolve();
  }

  /**
   * Dialog cetak browser: tiket dicetak sebagai satu gambar selebar kertas. Android sering
   * mencetak halaman sebagai A4 lalu mengecilkannya ke 58 mm; dengan gambar 100% lebar,
   * teks ikut membesar proporsional sehingga tetap terbaca (tidak hanya QR yang tercetak).
   */
  function tulisBrowser(rows) {
    var el = document.getElementById('print-area');
    el.innerHTML = '<img class="cetak-img" alt="" src="' + Resi.canvas(rows, 576).toDataURL('image/png') + '">';
    var img = el.querySelector('img');
    return new Promise(function (res) {
      function go() { window.print(); res(); }
      if (img.complete) go(); else { img.onload = go; img.onerror = go; }
    });
  }

  function kirim(rows) {
    var m = mode();
    if (m === 'none') return Promise.resolve();
    if (m === 'browser') return tulisBrowser(rows);
    var bytes = sebagaiGambar() ? rasterDariCanvas(Resi.canvas(rows, 384)) : escpos(rows);
    if (m === 'rawbt') return tulisRawBT(bytes);
    return tulisBT(bytes);
  }

  // ---------------------------------------------------------------- API publik
  /** Cetak; jenis = 'masuk' | 'keluar'. Menolak (reject) bila gagal agar pemanggil bisa mengantre. */
  function cetak(jenis, data, cfg) {
    try {
      return kirim(jenis === 'masuk' ? Resi.masuk(data, cfg) : Resi.keluar(data, cfg));
    } catch (e) {
      return Promise.reject(e);
    }
  }

  function tesCetak(cfg) {
    var rows = [['judul', (cfg && cfg.nama_lokasi) || 'PARKIR'], ['garis2'], ['tebal', 'TES PRINTER'],
      ['tengah', new Date().toLocaleString('id-ID')], ['kv', 'Lebar', LEBAR + ' karakter'], ['garis'],
      ['tengah', '12345678901234567890123456789012'], ['qr', 'PK1-TES'], ['tebal', 'Printer siap dipakai']];
    return kirim(rows);
  }

  global.Printer = {
    cetak: cetak, tesCetak: tesCetak, pilih: pilih, sambungUlang: sambungUlang, escpos: escpos, raster: rasterDariCanvas,
    status: status, mode: mode, setMode: setMode, onChange: function (f) { listeners.push(f); },
    sebagaiGambar: sebagaiGambar, setSebagaiGambar: function (v) { setOpsi('printer_gambar', v ? '1' : '0'); },
    ukuranPaket: ukuranPaket, setUkuranPaket: function (n) { setOpsi('printer_paket', n); }
  };
})(window);
