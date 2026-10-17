/**
 * Isi tiket masuk & struk keluar. Disusun sekali sebagai daftar baris, lalu dirender ke:
 *  - ESC/POS (printer thermal)                 -> Printer.escpos()
 *  - gambar (raster ESC/POS & dialog cetak)    -> Resi.canvas()
 *  - HTML (bukti di layar & pratinjau admin)   -> Resi.html()
 * sehingga yang dicetak selalu sama dengan yang terlihat di layar.
 *
 * Isi & tampilan diatur admin di menu "Tampilan Resi" (config.resi), lebar kertas 56/58 mm atau 80 mm.
 *
 * Jenis baris:
 *  ['judul', teks]      nama lokasi, tebal & besar, tengah
 *  ['tengah', teks]     teks rata tengah (dibungkus otomatis)
 *  ['tebal', teks]      teks tebal rata tengah
 *  ['besar', teks]      teks ukuran ganda dalam kotak (plat)
 *  ['kv', label, nilai] "Label    : nilai"
 *  ['lr', kiri, kanan]  kiri & kanan rata
 *  ['total', kiri, kanan] seperti lr, tebal & besar
 *  ['garis'] / ['garis2']  pemisah - / =
 *  ['qr', data]         kode QR
 *  ['poin', teks]       butir ketentuan "- ..." (dibungkus)
 *  ['kecil', teks]      teks keterangan rata tengah
 */
(function (global) {
  'use strict';

  /** Lebar kertas yang didukung. 56 mm dan 58 mm memakai area cetak yang sama (48 mm). */
  var KERTAS = {
    58: { mm: 58, label: '56/58 mm', kolom: 32, titik: 384 },
    80: { mm: 80, label: '80 mm', kolom: 48, titik: 576 }
  };

  /** Pengaturan bawaan; harus sama dengan resi_config_bawaan() di inc/helpers.php. */
  var BAWAAN = {
    kertas: 58,
    feed: 4,
    header_tambahan: '',
    masuk: {
      judul: 'TIKET PARKIR', jenis_di_judul: true, no_tiket: true, plat_besar: true,
      gerbang: true, petugas: true, no_bawah_qr: true, teks_qr: 'Tunjukkan tiket ini saat keluar',
      tarif: true, ketentuan: true, judul_ketentuan: 'KETENTUAN', denda: true
    },
    keluar: {
      judul: 'STRUK PARKIR', no_struk: true, no_tiket: true, plat_besar: true, jenis: true,
      durasi: true, rincian: true, tunai: true, gerbang: true, petugas: true, waktu_cetak: true,
      catatan: 'Simpan struk ini sebagai bukti pembayaran yang sah'
    }
  };

  function gabung(dasar, tambahan) {
    var o = {};
    Object.keys(dasar).forEach(function (k) {
      var v = tambahan && Object.prototype.hasOwnProperty.call(tambahan, k) ? tambahan[k] : undefined;
      o[k] = dasar[k] && typeof dasar[k] === 'object' ? gabung(dasar[k], v) : (v === undefined || v === null ? dasar[k] : v);
    });
    return o;
  }
  function opsi(cfg) { return gabung(BAWAAN, cfg && cfg.resi); }
  function kertas(mm) { return KERTAS[+mm === 80 ? 80 : 58]; }

  function rp(n) { return 'Rp' + Number(n || 0).toLocaleString('id-ID'); }
  function dua(n) { return ('0' + n).slice(-2); }
  /** "17/10/2026 14:43:05" — 19 karakter, muat satu baris di kertas 58 mm. */
  function tgl(s) {
    var d = Tarif.parse(s);
    return dua(d.getDate()) + '/' + dua(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + dua(d.getHours()) + ':' + dua(d.getMinutes()) + ':' + dua(d.getSeconds());
  }

  /**
   * Matriks QR untuk dicetak. Kode tiket ditulis huruf besar agar memakai mode alphanumeric:
   * QR lebih sederhana (33 modul vs 37), sehingga tiap modul bisa dicetak lebih besar dan tetap
   * terbaca walau tinta thermal sedikit melebar. Server menerima huruf besar maupun kecil.
   */
  function qr(data) {
    var s = String(data), up = s.toUpperCase(), q = qrcode(0, 'M');
    if (/^[0-9A-Z $%*+\-./:]*$/.test(up)) q.addData(up, 'Alphanumeric'); else q.addData(s);
    q.make();
    return q;
  }

  /**
   * Fungsi piksel QR (sudah termasuk quiet zone 2 modul) dengan kompensasi pelebaran tinta:
   * area hitam ditipiskan `e` titik di tiap tepi, karena kertas thermal melebarkan titik hitam
   * sehingga celah putih antar modul tertutup dan QR tidak terbaca.
   * Mengembalikan { sisi, gelap(x, y) } dalam satuan titik printer.
   */
  function qrPiksel(q, m, e) {
    var n = q.getModuleCount(), sisi = (n + 4) * m;
    e = Math.max(0, Math.min(e || 0, Math.floor((m - 1) / 2)));
    function modul(x, y) {
      var mx = Math.floor(x / m) - 2, my = Math.floor(y / m) - 2;
      return mx >= 0 && my >= 0 && mx < n && my < n && q.isDark(my, mx);
    }
    return {
      sisi: sisi,
      gelap: function (x, y) {
        if (!modul(x, y)) return false;
        if (!e) return true;
        // e < m, jadi jendela ±e hanya menyentuh modul di keempat sudutnya
        return modul(x - e, y - e) && modul(x + e, y - e) && modul(x - e, y + e) && modul(x + e, y + e);
      }
    };
  }

  /** Titik per modul QR: ±37 mm di kertas 58 mm, ±45 mm di kertas 80 mm. */
  function skalaQr(q, titikKertas) {
    var target = titikKertas >= 576 ? 360 : 300;
    return Math.max(2, Math.min(10, Math.floor(target / (q.getModuleCount() + 4))));
  }

  function kapital(s) { s = String(s || ''); return s.charAt(0).toUpperCase() + s.slice(1); }
  function noTiket(id) { return String(id || '').replace(/-/g, '').slice(0, 8).toUpperCase().replace(/^(.{4})(.{4})$/, '$1-$2'); }

  function kepala(cfg, o) {
    var r = [['judul', cfg.nama_lokasi || 'PARKIR']];
    if (cfg.alamat_lokasi) r.push(['tengah', cfg.alamat_lokasi]);
    if (cfg.telepon_lokasi) r.push(['tengah', 'Telp. ' + cfg.telepon_lokasi]);
    String(o.header_tambahan || '').split(/\r?\n/).forEach(function (b) { if (b.trim()) r.push(['tengah', b.trim()]); });
    r.push(['garis2']);
    return r;
  }

  /** Ringkasan tarif yang berlaku sekarang untuk dicetak di tiket masuk. */
  function infoTarif(cfg, jenis) {
    var t = Tarif.pilihTarif(cfg.tarif, jenis, Tarif.fmt(new Date()));
    if (!t) return null;
    var awal = +t.durasi_awal_menit;
    var awalTeks = awal % 60 === 0 ? (awal / 60) + ' jam pertama' : awal + ' menit pertama';
    var s = awalTeks + ' ' + rp(t.biaya_awal);
    if (+t.biaya_per_jam) s += ', berikutnya ' + rp(t.biaya_per_jam) + '/jam';
    if (+t.maks_per_hari) s += ', maks ' + rp(t.maks_per_hari) + '/hari';
    if (+t.biaya_menginap) s += '. Menginap ' + rp(t.biaya_menginap) + '/malam';
    return 'Tarif ' + jenis + ': ' + s + '.';
  }

  /** Tiket masuk (diberikan ke pengendara). */
  function masuk(t, cfg) {
    cfg = cfg || {};
    var o = opsi(cfg), m = o.masuk;
    var no = t.no_tiket || noTiket(t.id);
    var r = kepala(cfg, o);
    r.push(['tebal', (m.judul || 'TIKET PARKIR') + (m.jenis_di_judul ? ' ' + String(t.jenis || '').toUpperCase() : '')]);
    if (t.offline) r.push(['kecil', '(dibuat saat offline)']);
    r.push(['garis']);
    if (m.no_tiket) r.push(['kv', 'No. Tiket', no]);
    r.push([m.plat_besar ? 'besar' : 'tebal', t.plat]);
    if (!m.jenis_di_judul) r.push(['kv', 'Jenis', kapital(t.jenis)]);
    r.push(['kv', 'Masuk', tgl(t.waktu_masuk)]);
    if (m.gerbang) r.push(['kv', 'Gerbang', t.pos_masuk || '-']);
    if (m.petugas) r.push(['kv', 'Petugas', t.petugas || '-']);
    r.push(['qr', t.kode_qr]);
    if (m.no_bawah_qr) r.push(['tebal', no]);
    if (m.teks_qr) r.push(['kecil', m.teks_qr]);
    var tarif = m.tarif && infoTarif(cfg, t.jenis);
    if (tarif) { r.push(['garis']); r.push(['kecil', tarif]); }
    var poin = [];
    if (m.ketentuan) String(cfg.footer_resi || '').split(/\r?\n/).forEach(function (b) {
      b = b.trim();
      if (b && !(m.denda && /denda/i.test(b))) poin.push(b);
    });
    var denda = cfg.denda && cfg.denda[t.jenis];
    if (m.denda && denda) poin.push('Tiket hilang dikenakan denda ' + rp(denda) + ' dan wajib menunjukkan STNK');
    if (poin.length) {
      r.push(['garis']);
      if (m.judul_ketentuan) r.push(['tebal', m.judul_ketentuan]);
      poin.forEach(function (p) { r.push(['poin', p]); });
    }
    return r;
  }

  /** Struk keluar / bukti pembayaran. */
  function keluar(s, cfg) {
    cfg = cfg || {};
    var o = opsi(cfg), k = o.keluar;
    var r = kepala(cfg, o);
    r.push(['tebal', k.judul || 'STRUK PARKIR']);
    if (s.offline) r.push(['kecil', '(dibuat saat offline)']);
    r.push(['garis']);
    if (k.no_struk) r.push(['kv', 'No. Struk', s.no_struk || 'OFFLINE']);
    if (k.no_tiket) r.push(['kv', 'No. Tiket', s.no_tiket || '-']);
    r.push([k.plat_besar ? 'besar' : 'tebal', s.plat || '-']);
    if (k.jenis) r.push(['kv', 'Jenis', kapital(s.jenis)]);
    r.push(['kv', 'Masuk', tgl(s.waktu_masuk)]);
    r.push(['kv', 'Keluar', tgl(s.waktu_keluar)]);
    if (k.durasi) r.push(['kv', 'Durasi', Tarif.durasiTeks(s.durasi_menit)]);
    r.push(['garis']);
    if (k.rincian && (s.rincian || []).length) {
      s.rincian.forEach(function (x) { r.push(['lr', x[0], rp(x[1])]); });
      r.push(['garis']);
    }
    r.push(['total', 'TOTAL', rp(s.total)]);
    if (k.tunai && s.metode === 'cash' && s.tunai) {
      r.push(['lr', 'Tunai', rp(s.tunai)]);
      r.push(['lr', 'Kembali', rp(s.tunai - s.total)]);
    }
    r.push(['lr', 'Pembayaran', s.metode === 'qris' ? 'QRIS' : 'TUNAI']);
    if (s.tanpa_tiket) r.push(['kecil', 'Keluar tanpa tiket (verifikasi STNK)']);
    if (k.gerbang || k.petugas || k.waktu_cetak) {
      r.push(['garis']);
      if (k.gerbang) r.push(['kv', 'Gerbang', s.pos_keluar || '-']);
      if (k.petugas) r.push(['kv', 'Petugas', s.petugas || '-']);
      if (k.waktu_cetak) r.push(['kv', 'Dicetak', tgl(Tarif.fmt(new Date()))]);
    }
    r.push(['garis2']);
    if (cfg.footer_struk) r.push(['tebal', cfg.footer_struk]);
    if (k.catatan) r.push(['kecil', k.catatan]);
    return r;
  }

  // ---------------------------------------------------------------- render HTML
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  /** HTML bergaya kertas thermal. `mm` = 58 atau 80. */
  function html(rows, mm) {
    return '<div class="struk' + (kertas(mm).mm === 80 ? ' w80' : '') + '">' + rows.map(function (b) {
      switch (b[0]) {
        case 'judul': return '<div class="s-judul">' + esc(b[1]) + '</div>';
        case 'tengah': return '<div class="s-c">' + esc(b[1]) + '</div>';
        case 'tebal': return '<div class="s-c s-b">' + esc(b[1]) + '</div>';
        case 'besar': return '<div class="s-besar">' + esc(b[1]) + '</div>';
        case 'kv': return '<div class="s-kv"><span>' + esc(b[1]) + '</span><span>: ' + esc(b[2]) + '</span></div>';
        case 'lr': return '<div class="s-lr"><span>' + esc(b[1]) + '</span><span>' + esc(b[2]) + '</span></div>';
        case 'total': return '<div class="s-lr s-total"><span>' + esc(b[1]) + '</span><span>' + esc(b[2]) + '</span></div>';
        case 'garis': return '<div class="s-garis"></div>';
        case 'garis2': return '<div class="s-garis2"></div>';
        case 'qr':
          var q = qr(b[1]);
          return '<div class="s-qr">' + q.createSvgTag({ cellSize: 4, margin: 2, scalable: true }) + '</div>';
        case 'poin': return '<div class="s-poin">' + esc(b[1]) + '</div>';
        case 'kecil': return '<div class="s-c s-kecil">' + esc(b[1]) + '</div>';
      }
      return '';
    }).join('') + '</div>';
  }

  // ---------------------------------------------------------------- render gambar
  /** Bungkus teks per kata agar muat `lebar` kolom. */
  function bungkus(s, lebar) {
    var out = [], baris = '';
    String(s == null ? '' : s).split(/\s+/).forEach(function (k) {
      while (k.length > lebar) { if (baris) { out.push(baris); baris = ''; } out.push(k.slice(0, lebar)); k = k.slice(lebar); }
      if (!k) return;
      if (!baris) baris = k;
      else if ((baris + ' ' + k).length <= lebar) baris += ' ' + k;
      else { out.push(baris); baris = k; }
    });
    if (baris) out.push(baris);
    return out.length ? out : [''];
  }

  /**
   * Gambar tiket/struk ke canvas hitam-putih.
   * p.mm      : 58 / 80 (lebar kertas, menentukan jumlah kolom)
   * p.skala   : pengali resolusi (1 = titik printer; >1 untuk dialog cetak browser)
   * p.qrTipis : kompensasi pelebaran tinta QR (titik printer)
   * Dipakai untuk cetak sebagai gambar (raster ESC/POS) dan dialog cetak browser, agar teks selalu
   * tercetak setebal QR di printer apa pun dan pada ukuran kertas apa pun.
   */
  function canvas(rows, p) {
    p = p || {};
    var K = kertas(p.mm), skala = p.skala || 1, KOL = K.kolom;
    var W = Math.round(K.titik * skala), PAD = Math.round(6 * skala), LBL = 10;
    var c = document.createElement('canvas'), x = c.getContext('2d');
    var FONT = '"Courier New", "Roboto Mono", monospace';
    // Ukuran huruf agar tepat KOL karakter per baris
    x.font = 'bold 20px ' + FONT;
    var fs = Math.floor(20 * (W - PAD * 2) / x.measureText(new Array(KOL + 1).join('M')).width);
    var lh = Math.round(fs * 1.25);
    var cw = (W - PAD * 2) / KOL;

    function jalankan(gambar) {
      var y = PAD;
      function teks(s, size, align) {
        if (gambar) {
          x.font = 'bold ' + size + 'px ' + FONT;
          x.textAlign = align; x.textBaseline = 'top';
          x.fillText(s, align === 'center' ? W / 2 : align === 'right' ? W - PAD : PAD, y + (lh * size / fs - size) / 2);
        }
        y += Math.round(lh * size / fs);
      }
      function garis(ganda) {
        var t = Math.max(2, Math.round(2 * skala));
        if (gambar) {
          x.fillStyle = '#000';
          if (ganda) { x.fillRect(PAD, y + 2 * t, W - PAD * 2, t); x.fillRect(PAD, y + 4.5 * t, W - PAD * 2, t); }
          else for (var gx = PAD; gx < W - PAD; gx += 5 * t) x.fillRect(gx, y + 3 * t, 3 * t, t);
        }
        y += 7 * t;
      }
      rows.forEach(function (r) {
        switch (r[0]) {
          case 'judul': bungkus(r[1], Math.floor(KOL / 1.35)).forEach(function (s) { teks(s, Math.round(fs * 1.35), 'center'); }); y += 2; break;
          case 'tengah': case 'kecil': case 'tebal': bungkus(r[1], KOL).forEach(function (s) { teks(s, fs, 'center'); }); break;
          case 'besar':
            var bs = Math.round(fs * 2), lebarTeks = 0, tb = Math.max(3, Math.round(4 * skala));
            if (gambar) { x.font = 'bold ' + bs + 'px ' + FONT; lebarTeks = Math.min(W - PAD * 2, x.measureText(r[1]).width + 24 * skala); }
            y += Math.round(6 * skala);
            if (gambar) { x.lineWidth = tb; x.strokeRect((W - lebarTeks) / 2, y - 2, lebarTeks, Math.round(lh * 2) + 4); }
            teks(r[1], bs, 'center');
            y += Math.round(10 * skala); break;
          case 'kv':
            bungkus(r[2], KOL - LBL - 2).forEach(function (s, i) {
              if (gambar) { x.font = 'bold ' + fs + 'px ' + FONT; x.textAlign = 'left'; x.textBaseline = 'top';
                x.fillText(i ? '' : r[1], PAD, y); x.fillText((i ? '  ' : ': ') + s, PAD + cw * LBL, y); }
              y += lh;
            });
            break;
          case 'lr': case 'total':
            var sz = r[0] === 'total' ? Math.round(fs * 1.5) : fs;
            if (gambar) { x.font = 'bold ' + sz + 'px ' + FONT; x.textBaseline = 'top';
              x.textAlign = 'left'; x.fillText(r[1], PAD, y); x.textAlign = 'right'; x.fillText(r[2], W - PAD, y); }
            y += Math.round(lh * sz / fs); break;
          case 'garis': garis(false); break;
          case 'garis2': garis(true); break;
          case 'qr':
            var q = qr(r[1]), m = Math.round(skalaQr(q, K.titik) * skala), pk = qrPiksel(q, m, Math.round((p.qrTipis || 0) * skala));
            var sisi = pk.sisi, ox = Math.round((W - sisi) / 2);
            if (gambar) {
              var img = x.createImageData(sisi, sisi);
              for (var py = 0; py < sisi; py++) for (var pxl = 0; pxl < sisi; pxl++) {
                var v = pk.gelap(pxl, py) ? 0 : 255, ip = (py * sisi + pxl) * 4;
                img.data[ip] = img.data[ip + 1] = img.data[ip + 2] = v; img.data[ip + 3] = 255;
              }
              x.putImageData(img, ox, y);
            }
            y += sisi + Math.round(4 * skala); break;
          case 'poin':
            bungkus(r[1], KOL - 2).forEach(function (s, i) {
              if (gambar) { x.font = 'bold ' + fs + 'px ' + FONT; x.textAlign = 'left'; x.textBaseline = 'top'; x.fillText((i ? '  ' : '- ') + s, PAD, y); }
              y += lh;
            });
            break;
        }
      });
      return y + PAD;
    }

    c.width = W;
    c.height = jalankan(false);
    x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height);
    x.fillStyle = '#000'; x.strokeStyle = '#000';
    jalankan(true);
    return c;
  }

  global.Resi = {
    masuk: masuk, keluar: keluar, html: html, canvas: canvas, bungkus: bungkus,
    qr: qr, qrPiksel: qrPiksel, skalaQr: skalaQr, noTiket: noTiket, rp: rp,
    KERTAS: KERTAS, BAWAAN: BAWAAN, opsi: opsi, kertas: kertas
  };
})(window);
