/**
 * Cetak resi ke printer thermal 58 mm (32 karakter, 384 dot).
 *  - bluetooth : Web Bluetooth + ESC/POS (Chrome Android, butuh HTTPS)
 *  - rawbt     : kirim ESC/POS ke aplikasi RawBT (Android) lewat intent
 *  - browser   : dialog cetak browser (cadangan)
 * QR dicetak sebagai gambar raster (GS v 0) agar didukung printer murah.
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
    var n = q.getModuleCount(), quiet = 2, scale = dotsPerModule || Math.max(2, Math.min(8, Math.floor(300 / (n + quiet * 2))));
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
  function rp(n) { return 'Rp' + Number(n || 0).toLocaleString('id-ID'); }
  function tgl(s) { var d = Tarif.parse(s); return ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + '/' + d.getFullYear() + ' ' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }

  function kepala(b, cfg) {
    b.align(1).bold(true).size(1, 2).line((cfg.nama_lokasi || 'PARKIR').slice(0, LEBAR)).size(1, 1).bold(false);
    if (cfg.alamat_lokasi) b.line(String(cfg.alamat_lokasi).slice(0, LEBAR * 2));
    return b;
  }

  /** Resi masuk: tiket berisi QR. */
  function resiMasuk(t, cfg) {
    var b = kepala(new Builder(), cfg);
    b.rule().bold(true).line('TIKET MASUK' + (t.offline ? ' (OFFLINE)' : '')).bold(false);
    b.size(2, 2).bold(true).line(t.plat).size(1, 1).bold(false);
    b.line(String(t.jenis).toUpperCase());
    b.qr(t.kode_qr);
    b.align(0).pair('Masuk', tgl(t.waktu_masuk)).pair('Pos', String(t.pos_masuk || '').slice(0, 24)).pair('Petugas', String(t.petugas || '').slice(0, 22));
    b.rule().align(1).line(cfg.footer_resi || 'Simpan tiket ini.');
    var denda = cfg.denda && cfg.denda[t.jenis];
    if (denda) b.line('Tiket hilang: denda ' + rp(denda));
    return b.feed(3).cut().bytes();
  }

  /** Resi keluar dengan rincian biaya. */
  function resiKeluar(r, cfg) {
    var b = kepala(new Builder(), cfg);
    b.rule().bold(true).line('RESI KELUAR' + (r.offline ? ' (OFFLINE)' : '')).bold(false);
    b.size(2, 2).bold(true).line(r.plat || '-').size(1, 1).bold(false).line(String(r.jenis).toUpperCase());
    b.align(0).rule().pair('Masuk', tgl(r.waktu_masuk)).pair('Keluar', tgl(r.waktu_keluar)).pair('Durasi', Tarif.durasiTeks(r.durasi_menit));
    b.rule();
    (r.rincian || []).forEach(function (x) { b.pair(String(x[0]).slice(0, 20), rp(x[1])); });
    b.rule().bold(true).size(1, 2).pair('TOTAL', rp(r.total)).size(1, 1).bold(false);
    b.pair('Bayar', String(r.metode).toUpperCase());
    if (r.tanpa_tiket) b.line('Keluar tanpa tiket');
    b.pair('Petugas', String(r.petugas || '').slice(0, 22));
    b.rule().align(1).line('Terima kasih');
    return b.feed(3).cut().bytes();
  }

  // ---------------------------------------------------------------- Bluetooth
  var dev = null, chr = null, listeners = [];

  function status() {
    return { mode: mode(), connected: !!(chr && dev && dev.gatt && dev.gatt.connected), name: dev ? dev.name : null };
  }
  function notify() { listeners.forEach(function (f) { try { f(status()); } catch (e) {} }); }
  function mode() { try { return localStorage.getItem('printer_mode') || (navigator.bluetooth ? 'bluetooth' : 'browser'); } catch (e) { return 'browser'; } }
  function setMode(m) { try { localStorage.setItem('printer_mode', m); } catch (e) {} notify(); }

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
      var off = 0, CHUNK = 180;
      function step() {
        if (off >= data.length) return Promise.resolve();
        var part = data.slice(off, off + CHUNK);
        off += CHUNK;
        var p = chr.properties.writeWithoutResponse && chr.writeValueWithoutResponse ? chr.writeValueWithoutResponse(part) : chr.writeValue(part);
        return p.then(function () { return new Promise(function (r) { setTimeout(r, 20); }); }).then(step);
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

  function htmlResi(jenis, d, cfg) {
    var e = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    var h = '<div class="r-h"><b>' + e(cfg.nama_lokasi) + '</b><br>' + e(cfg.alamat_lokasi || '') + '</div><hr>';
    if (jenis === 'masuk') {
      var q = qrcode(0, 'M'); q.addData(d.kode_qr); q.make();
      h += '<div class="r-c"><b>TIKET MASUK</b><div class="r-plat">' + e(d.plat) + '</div>' + e(String(d.jenis).toUpperCase()) +
        '<div class="r-qr">' + q.createSvgTag({ cellSize: 4, margin: 2 }) + '</div></div>' +
        '<table><tr><td>Masuk</td><td>' + e(tgl(d.waktu_masuk)) + '</td></tr><tr><td>Pos</td><td>' + e(d.pos_masuk) + '</td></tr><tr><td>Petugas</td><td>' + e(d.petugas) + '</td></tr></table><hr><div class="r-c">' + e(cfg.footer_resi) + '</div>';
    } else {
      h += '<div class="r-c"><b>RESI KELUAR</b><div class="r-plat">' + e(d.plat || '-') + '</div></div><table>' +
        '<tr><td>Masuk</td><td>' + e(tgl(d.waktu_masuk)) + '</td></tr><tr><td>Keluar</td><td>' + e(tgl(d.waktu_keluar)) + '</td></tr>' +
        '<tr><td>Durasi</td><td>' + e(Tarif.durasiTeks(d.durasi_menit)) + '</td></tr>' +
        (d.rincian || []).map(function (x) { return '<tr><td>' + e(x[0]) + '</td><td>' + e(rp(x[1])) + '</td></tr>'; }).join('') +
        '<tr class="r-tot"><td>TOTAL</td><td>' + e(rp(d.total)) + '</td></tr><tr><td>Bayar</td><td>' + e(String(d.metode).toUpperCase()) + '</td></tr></table><hr><div class="r-c">Terima kasih</div>';
    }
    return h;
  }
  function tulisBrowser(jenis, d, cfg) {
    var el = document.getElementById('print-area');
    el.innerHTML = htmlResi(jenis, d, cfg);
    window.print();
    return Promise.resolve();
  }

  // ---------------------------------------------------------------- API publik
  /** Cetak; jenis = 'masuk' | 'keluar'. Menolak (reject) bila gagal agar pemanggil bisa mengantre. */
  function cetak(jenis, data, cfg) {
    var m = mode();
    if (m === 'none') return Promise.resolve();
    if (m === 'browser') return tulisBrowser(jenis, data, cfg);
    var bytes = jenis === 'masuk' ? resiMasuk(data, cfg) : resiKeluar(data, cfg);
    if (m === 'rawbt') return tulisRawBT(bytes);
    return tulisBT(bytes);
  }

  function tesCetak(cfg) {
    var b = kepala(new Builder(), cfg).rule().line('TES PRINTER').line(new Date().toLocaleString('id-ID')).qr('PK1-TES').feed(3).cut();
    var m = mode();
    if (m === 'rawbt') return tulisRawBT(b.bytes());
    if (m === 'bluetooth') return tulisBT(b.bytes());
    return tulisBrowser('masuk', { plat: 'TES', jenis: 'motor', kode_qr: 'PK1-TES', waktu_masuk: Tarif.fmt(new Date()), pos_masuk: '-', petugas: '-' }, cfg);
  }

  global.Printer = {
    cetak: cetak, tesCetak: tesCetak, pilih: pilih, sambungUlang: sambungUlang,
    status: status, mode: mode, setMode: setMode, onChange: function (f) { listeners.push(f); }
  };
})(window);
