/* ParkIt — aplikasi petugas (PWA). Tanpa framework; satu berkas logika UI. */
(function () {
  'use strict';

  var API = '../api/index.php?r=';
  var VERSI_APP = window.PARKIT_VERSI || 'dev'; // dari pwa/versi.js
  var S = {
    token: null, petugas: null, shift: null, config: null,
    online: navigator.onLine, tab: 'kerja', jenis: 'motor',
    stream: null, scanner: null, scanSibuk: false
  };

  // ================================================================ utilitas
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function rp(n) { return 'Rp' + Number(n || 0).toLocaleString('id-ID'); }
  function jam(s) { var d = Tarif.parse(s); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
  function tglJam(s) { var d = Tarif.parse(s); return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short' }) + ' ' + jam(s); }
  function load(k, d) { try { var v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } }
  function save(k, v) { try { if (v === null || v === undefined) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function sekarang() { return Tarif.fmt(new Date()); }
  function getar(pola) { try { navigator.vibrate && navigator.vibrate(pola); } catch (e) {} }
  var audioCtx = null;
  function bunyi(ok) {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      var o = audioCtx.createOscillator(), g = audioCtx.createGain();
      o.frequency.value = ok ? 1320 : 220; o.type = ok ? 'sine' : 'square';
      g.gain.value = 0.15; o.connect(g); g.connect(audioCtx.destination);
      o.start(); o.stop(audioCtx.currentTime + (ok ? 0.12 : 0.35));
    } catch (e) {}
  }
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
    var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
  }
  function hexAcak(n) { return Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(n)), function (x) { return ('0' + x.toString(16)).slice(-2); }).join('').slice(0, n); }
  function normPlat(p) {
    p = String(p || '').toUpperCase().replace(/[^A-Z0-9]/g, '');
    var m = p.match(/^([A-Z]{1,2})(\d{1,4})([A-Z]{0,3})$/);
    return m ? (m[1] + ' ' + m[2] + ' ' + m[3]).trim() : p.slice(0, 15);
  }

  var toastTimer = null;
  function toast(msg, tipe, ms) {
    var t = $('#toast');
    t.textContent = msg; t.className = 'toast ' + (tipe || ''); t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { t.hidden = true; }, ms || 2600);
  }

  // ================================================================ API
  function ApiError(msg, kode, status, data) { this.message = msg; this.kode = kode; this.status = status; this.data = data; }
  ApiError.prototype = Object.create(Error.prototype);

  function api(rute, opts) {
    opts = opts || {};
    var ctrl = window.AbortController ? new AbortController() : null;
    var timer = setTimeout(function () { ctrl && ctrl.abort(); }, opts.timeout || 15000);
    var headers = {};
    var body = opts.body;
    if (S.token) headers.Authorization = 'Bearer ' + S.token;
    headers['X-App-Versi'] = VERSI_APP;
    if (body && !(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
    return fetch(API + rute + (opts.query || ''), {
      method: opts.method || (body ? 'POST' : 'GET'), headers: headers, body: body, signal: ctrl ? ctrl.signal : undefined, cache: 'no-store'
    }).then(function (res) {
      clearTimeout(timer);
      setOnline(true);
      return res.json().catch(function () { throw new ApiError('Respons server tidak valid', 'server', res.status); }).then(function (j) {
        if (!j.ok) {
          if (res.status === 401) sesiHabis();
          throw new ApiError(j.error || 'Gagal', j.kode, res.status, j.data);
        }
        return j.data;
      });
    }, function (err) {
      clearTimeout(timer);
      setOnline(false);
      var e = new ApiError(err && err.name === 'AbortError' ? 'Server tidak merespons' : 'Tidak ada koneksi ke server', 'network', 0);
      e.network = true;
      throw e;
    });
  }

  function setOnline(v) {
    if (S.online === v) return;
    S.online = v;
    renderChips();
    if (v) sinkron();
  }

  // ================================================================ sesi
  function sesiHabis() {
    save('sesi', null);
    S.token = null;
    stopKamera(); stopScanner();
    toast('Sesi berakhir, silakan login ulang', 'bad');
    tampil('login');
  }

  function simpanSesi() {
    save('sesi', { token: S.token, petugas: S.petugas, shift: S.shift, config: S.config });
  }

  function tampil(nama) {
    ['login', 'pos'].forEach(function (n) { $('#s-' + n).hidden = n !== nama; });
    $('#app').hidden = nama !== 'app';
    if (nama !== 'app') { stopKamera(); stopScanner(); }
  }

  function mulai() {
    var sesi = load('sesi', null);
    if (sesi && sesi.token) {
      S.token = sesi.token; S.petugas = sesi.petugas; S.shift = sesi.shift; S.config = sesi.config;
      masukAplikasi();
      api('me').then(function (d) {
        S.petugas = d.petugas; S.config = d.config;
        var berubah = (S.shift && S.shift.id) !== (d.shift && d.shift.id);
        S.shift = d.shift;
        simpanSesi();
        if (berubah) masukAplikasi(); else renderChips();
      }).catch(function () {});
    } else {
      tampil('login');
    }
  }

  function masukAplikasi() {
    if (!S.shift) {
      $('#pos-nama-petugas').textContent = S.petugas ? S.petugas.nama : '';
      var f = $('#f-pos');
      f.pos_nama.value = load('pos_nama', '');
      var p = load('pos_terakhir', null);
      if (p) $$('input[name=pos]', f).forEach(function (r) { r.checked = r.value === p; });
      tampil('pos');
      return;
    }
    document.body.classList.toggle('pos-keluar', S.shift.pos === 'keluar');
    $('#tb-pos').textContent = (S.shift.pos === 'masuk' ? 'MASUK · ' : 'KELUAR · ') + (S.shift.pos_nama || '');
    $('#tb-petugas').textContent = S.petugas.nama + ' · shift ' + jam(S.shift.waktu_buka);
    $('#tab-kerja-label').textContent = S.shift.pos === 'masuk' ? 'Kamera' : 'Scan';
    tampil('app');
    pilihTab('kerja');
    renderChips();
    kunciLayar();
    jagaPrinter();
  }

  $('#f-login').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var f = ev.target, btn = $('button', f);
    btn.disabled = true; btn.textContent = 'Memeriksa…';
    api('auth.login', { body: { username: f.username.value.trim(), password: f.password.value } }).then(function (d) {
      S.token = d.token; S.petugas = d.petugas; S.shift = d.shift; S.config = d.config;
      simpanSesi();
      f.password.value = '';
      masukAplikasi();
    }).catch(function (e) {
      toast(e.message, 'bad'); getar(200);
    }).then(function () { btn.disabled = false; btn.textContent = 'Masuk'; });
  });

  $('#f-pos').addEventListener('submit', function (ev) {
    ev.preventDefault();
    var f = ev.target, pos = (f.querySelector('input[name=pos]:checked') || {}).value;
    if (!pos) return toast('Pilih Pos Masuk atau Pos Keluar', 'bad');
    save('pos_nama', f.pos_nama.value.trim()); save('pos_terakhir', pos);
    api('shift.buka', { body: { pos: pos, pos_nama: f.pos_nama.value.trim() } }).then(function (s) {
      S.shift = s; simpanSesi(); masukAplikasi();
      toast('Shift dibuka. Selamat bertugas!', 'ok');
    }).catch(function (e) { toast(e.message, 'bad'); });
  });

  function logout() {
    Outbox.jumlah().then(function (n) {
      if (n && !confirm(n + ' transaksi offline belum tersinkron dan akan tetap tersimpan di perangkat ini. Tetap keluar?')) return;
      if (S.shift && !confirm('Shift masih terbuka. Keluar akun tanpa menutup shift?')) return;
      api('auth.logout', { method: 'POST', body: {} }).catch(function () {});
      Printer.berhentiJaga(true);
      save('sesi', null); S.token = null; S.shift = null;
      tampil('login');
    });
  }
  $('#btn-logout').addEventListener('click', logout);
  $('#btn-logout-pos').addEventListener('click', logout);

  // ================================================================ tab & status
  $$('.tabbar button').forEach(function (b) { b.addEventListener('click', function () { pilihTab(b.dataset.tab); }); });

  function pilihTab(tab) {
    S.tab = tab;
    $$('.tabbar button').forEach(function (b) { b.classList.toggle('on', b.dataset.tab === tab); });
    var masuk = S.shift && S.shift.pos === 'masuk';
    $('#s-masuk').hidden = !(tab === 'kerja' && masuk);
    $('#s-keluar').hidden = !(tab === 'kerja' && !masuk);
    $('#s-riwayat').hidden = tab !== 'riwayat';
    $('#s-shift').hidden = tab !== 'shift';
    if (tab === 'kerja' && masuk) { stopScanner(); startKamera(); } else stopKamera();
    if (tab === 'kerja' && !masuk) startScanner(); else stopScanner();
    if (tab === 'riwayat') renderRiwayat();
    if (tab === 'shift') renderShift();
  }

  function renderChips() {
    var net = $('#chip-net');
    net.textContent = S.online ? '● Online' : '● Offline';
    net.className = 'chip ' + (S.online ? 'ok' : 'warn');
    Outbox.jumlah().then(function (n) {
      var c = $('#chip-sync');
      c.hidden = !n; c.textContent = '⇅ ' + n + ' antre';
    }).catch(function () {});
    var ps = Printer.status(), pc = $('#chip-print');
    if (ps.mode === 'bluetooth') {
      pc.textContent = ps.connected ? '🖨 Siap' : ps.connecting ? '🖨 Menyambung…' : '🖨 Putus';
      pc.className = 'chip ' + (ps.connected ? 'ok' : ps.connecting ? 'warn' : 'bad');
    } else { pc.textContent = '🖨 ' + ({ rawbt: 'RawBT', browser: 'Browser', none: 'Mati' }[ps.mode] || ps.mode); pc.className = 'chip'; }
    renderBannerPrinter(ps);
    var belum = load('riwayat', []).filter(function (r) { return r.belumCetak; }).length;
    var bc = $('#badge-cetak'); bc.hidden = !belum; bc.textContent = belum;
  }
  Printer.onChange(renderChips);

  // ---------------------------------------------------------------- jaga koneksi printer
  /** Jaga koneksi printer Bluetooth selama shift berjalan (sambung ulang otomatis + keep-alive). */
  function jagaPrinter() {
    if (!S.shift || Printer.mode() !== 'bluetooth' || !navigator.bluetooth) return;
    Printer.mulaiJaga().catch(function () {}); // status & banner diperbarui lewat Printer.onChange
  }

  var printerSebelumnya = null, waktuPutus = 0;
  function renderBannerPrinter(ps) {
    var b = $('#banner-printer');
    if (!b) return;
    var tampilkan = !!S.shift && ps.mode === 'bluetooth' && ps.jaga && !ps.connected;
    b.hidden = !tampilkan;
    if (tampilkan) {
      b.className = 'banner-printer ' + (ps.perluPilih ? 'bad' : 'warn');
      $('#bp-teks').textContent = ps.perluPilih
        ? 'Printer belum tersambung. Ketuk Hubungkan lalu pilih printer.'
        : 'Printer terputus — menyambung ulang otomatis…' + (ps.galat ? ' (' + ps.galat + ')' : '');
      $('#bp-aksi').textContent = ps.perluPilih ? 'Hubungkan' : 'Sambung sekarang';
    }
    // Notifikasi sekali saat status berubah selama shift
    if (ps.mode === 'bluetooth' && ps.jaga && printerSebelumnya !== null && printerSebelumnya !== ps.connected) {
      if (!ps.connected) { waktuPutus = Date.now(); getar([120, 80, 120]); toast('Printer terputus — menyambung ulang…', 'bad', 3500); }
      else if (waktuPutus) { toast('Printer tersambung kembali', 'ok'); waktuPutus = 0; }
    }
    printerSebelumnya = ps.jaga ? ps.connected : null;
  }

  /** Dipanggil langsung dari ketukan: dialog pilih printer hanya boleh dibuka dari gesture pengguna. */
  function hubungkanPrinter() {
    var ps = Printer.status();
    var p = ps.perluPilih || !ps.name ? Printer.pilih() : Printer.sambungUlang();
    p.then(function () {
      toast('Printer tersambung', 'ok');
      if (S.shift) Printer.mulaiJaga().catch(function () {});
      if (!$('#s-shift').hidden) renderPrinter();
    }).catch(function (e) {
      if (e && e.name === 'NotFoundError') return; // dialog dibatalkan
      toast(e.message || String(e), 'bad', 4500);
    });
  }
  $('#chip-net').addEventListener('click', function () { api('me').then(function () { toast('Terhubung ke server', 'ok'); }).catch(function (e) { toast(e.message, 'bad'); }); });
  $('#chip-sync').addEventListener('click', function () { pilihTab('shift'); });
  $('#chip-print').addEventListener('click', function () {
    if (Printer.mode() !== 'bluetooth') return pilihTab('shift');
    hubungkanPrinter();
  });

  if (navigator.getBattery) {
    navigator.getBattery().then(function (b) {
      function upd() {
        var c = $('#chip-bat'), p = Math.round(b.level * 100);
        c.hidden = p > 30 && !b.charging; c.textContent = (b.charging ? '⚡' : '🔋') + p + '%';
        c.className = 'chip ' + (p <= 20 && !b.charging ? 'bad' : '');
        if (p === 15 && !b.charging) toast('Baterai HP 15% — sambungkan powerbank', 'bad', 5000);
      }
      upd(); b.addEventListener('levelchange', upd); b.addEventListener('chargingchange', upd);
    });
  }

  var wakeLock = null;
  function kunciLayar() {
    if (!('wakeLock' in navigator) || wakeLock) return;
    navigator.wakeLock.request('screen').then(function (l) { wakeLock = l; l.addEventListener('release', function () { wakeLock = null; }); }).catch(function () {});
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible' && S.shift) { kunciLayar(); if (S.tab === 'kerja') pilihTab('kerja'); }
    else { stopKamera(); stopScanner(); }
  });
  window.addEventListener('online', function () { api('me').catch(function () {}); });
  window.addEventListener('offline', function () { setOnline(false); });

  // ================================================================ sheet
  function bukaSheet(html, onClose) {
    $('#sheet-body').innerHTML = html;
    $('#sheet').hidden = false;
    S.sheetClose = onClose || null;
    return $('#sheet-body');
  }
  function tutupSheet() {
    $('#sheet').hidden = true;
    $('#sheet-body').innerHTML = '';
    var cb = S.sheetClose; S.sheetClose = null;
    if (cb) cb();
  }
  $('#sheet').addEventListener('click', function (e) { if (e.target.id === 'sheet') tutupSheet(); });

  // ================================================================ riwayat lokal & cetak
  function catatRiwayat(item) {
    var r = load('riwayat', []);
    r = r.filter(function (x) { return x.key !== item.key; });
    r.unshift(item);
    save('riwayat', r.slice(0, 20));
    renderChips();
  }

  function cetak(jenis, data, key) {
    return Printer.cetak(jenis, data, S.config || {}).then(function () {
      tandaiCetak(key, false);
      return true;
    }, function (e) {
      tandaiCetak(key, true);
      toast('Gagal mencetak: ' + (e.message || e) + '. Cetak ulang dari tab Riwayat.', 'bad', 5000);
      getar([100, 60, 100]);
      return false;
    });
  }
  function tandaiCetak(key, belum) {
    var r = load('riwayat', []);
    r.forEach(function (x) { if (x.key === key) x.belumCetak = belum; });
    save('riwayat', r);
    renderChips();
  }

  /**
   * Tampilkan bukti (tiket/struk) di layar seperti kertas aslinya, sekaligus mencetaknya.
   * opsi.auto     : detik sebelum kembali otomatis setelah cetak berhasil (0 = tidak)
   * opsi.cetak    : false = hanya tampilkan (dari Riwayat)
   * opsi.onSelesai: dipanggil saat lembar ditutup
   */
  function tampilBukti(jenis, data, key, opsi) {
    opsi = opsi || {};
    var rows = jenis === 'masuk' ? Resi.masuk(data, S.config || {}) : Resi.keluar(data, S.config || {});
    var el = bukaSheet('<div class="bukti-wrap" id="bukti-wrap">' + Resi.html(rows, Printer.kertasAktif(S.config || {}).mm) + '</div>' +
      '<div class="bukti-aksi"><div class="cetak-status" id="cetak-status"></div>' +
      '<div class="row"><button class="btn ghost grow" id="b-cetak-ulang">🖨 Cetak ulang</button>' +
      '<button class="btn primary grow" id="b-bukti-ok">Selesai</button></div></div>', opsi.onSelesai);
    var st = $('#cetak-status', el), ok = $('#b-bukti-ok', el), timer = null, sisa = opsi.auto || 0;

    function masihTerbuka() { return document.getElementById('b-bukti-ok') === ok; }
    function batalAuto() { if (timer) { clearInterval(timer); timer = null; } ok.textContent = 'Selesai'; }
    function mulaiAuto() {
      if (!sisa || timer) return;
      ok.textContent = 'Selesai (' + sisa + ')';
      timer = setInterval(function () {
        if (!masihTerbuka()) return clearInterval(timer);
        if (--sisa <= 0) { clearInterval(timer); tutupSheet(); } else ok.textContent = 'Selesai (' + sisa + ')';
      }, 1000);
    }
    function jalankanCetak() {
      st.className = 'cetak-status'; st.innerHTML = '<span class="spin"></span> Mencetak…';
      return cetak(jenis, data, key).then(function (berhasil) {
        if (!masihTerbuka()) return;
        if (berhasil) {
          st.className = 'cetak-status ok';
          st.textContent = Printer.mode() === 'none' ? 'Mode tanpa cetak — bukti hanya di layar' : '✓ Terkirim ke printer';
          mulaiAuto();
        } else {
          st.className = 'cetak-status bad';
          st.textContent = '✕ Gagal mencetak. Periksa printer, lalu tekan Cetak ulang.';
          batalAuto();
        }
      });
    }

    $('#b-cetak-ulang', el).addEventListener('click', function () { batalAuto(); sisa = 0; jalankanCetak(); });
    ok.addEventListener('click', tutupSheet);
    // Petugas sedang membaca bukti: jangan tutup otomatis.
    $('#bukti-wrap', el).addEventListener('pointerdown', function () { batalAuto(); sisa = 0; });
    if (opsi.cetak === false) {
      var x = load('riwayat', []).filter(function (r) { return r.key === key; })[0];
      st.className = 'cetak-status ' + (x && x.belumCetak ? 'bad' : '');
      st.textContent = x && x.belumCetak ? 'Belum tercetak' : 'Bukti tersimpan di perangkat ini';
    } else {
      jalankanCetak();
    }
  }

  function renderRiwayat() {
    var r = load('riwayat', []), el = $('#list-riwayat');
    if (!r.length) { el.innerHTML = '<div class="empty">Belum ada transaksi di perangkat ini.</div>'; return; }
    el.innerHTML = r.map(function (x, i) {
      var d = x.data, masuk = x.jenis === 'masuk';
      return '<div class="item"><div class="row between"><span class="plat">' + esc(d.plat || '-') + '</span>' +
        '<span class="tag ' + (masuk ? 'in' : 'out') + '">' + (masuk ? 'Masuk' : 'Keluar') + '</span></div>' +
        '<div class="row between small muted"><span>' + esc(d.jenis) + ' · ' + tglJam(masuk ? d.waktu_masuk : d.waktu_keluar) + '</span>' +
        (masuk ? '' : '<b style="color:var(--text)">' + rp(d.total) + ' · ' + esc(String(d.metode).toUpperCase()) + '</b>') + '</div>' +
        '<div class="row">' + (x.belumCetak ? '<span class="tag bad">Belum tercetak</span>' : '') + (d.offline ? '<span class="tag warn">Offline</span>' : '') +
        (x.voidDiminta ? '<span class="tag bad">Void diminta</span>' : '') + '</div>' +
        '<div class="row"><button class="btn ghost sm grow" data-lihat="' + i + '">🧾 Lihat bukti</button>' +
        '<button class="btn ' + (x.belumCetak ? 'primary' : 'ghost') + ' sm grow" data-cetak="' + i + '">🖨 Cetak ulang</button>' +
        (masuk && !d.offline && !x.voidDiminta ? '<button class="btn ghost sm" data-void="' + i + '">Minta void</button>' : '') + '</div></div>';
    }).join('');
  }
  $('#list-riwayat').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var r = load('riwayat', []);
    if (b.dataset.lihat) {
      var z = r[+b.dataset.lihat];
      tampilBukti(z.jenis, z.data, z.key, { cetak: false, onSelesai: renderRiwayat });
    } else if (b.dataset.cetak) {
      var x = r[+b.dataset.cetak];
      cetak(x.jenis, x.data, x.key).then(function (ok) { if (ok) toast('Dicetak ulang', 'ok'); renderRiwayat(); });
    } else if (b.dataset.void) {
      var y = r[+b.dataset.void];
      var alasan = prompt('Alasan pembatalan tiket ' + y.data.plat + ' (mis. salah plat, salah jenis, kendaraan batal masuk):');
      if (!alasan) return;
      api('masuk.void_minta', { body: { id: y.data.id, alasan: alasan } }).then(function () {
        y.voidDiminta = true; save('riwayat', r); renderRiwayat();
        toast('Permintaan void dikirim ke admin', 'ok');
      }).catch(function (er) { toast(er.message, 'bad'); });
    }
  });

  // ================================================================ POS MASUK
  function kameraTersedia() { return window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia; }

  function startKamera() {
    if (S.stream) return;
    var msg = $('#cam-msg');
    if (!kameraTersedia()) {
      msg.hidden = false;
      msg.innerHTML = 'Kamera langsung butuh HTTPS.<br>Gunakan tombol 📷 (kamera bawaan) atau ⌨ (ketik manual).';
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 960 } }, audio: false })
      .then(function (st) {
        if (S.tab !== 'kerja' || $('#s-masuk').hidden) { st.getTracks().forEach(function (t) { t.stop(); }); return; }
        S.stream = st; $('#cam').srcObject = st; msg.hidden = true;
      }).catch(function (e) {
        msg.hidden = false;
        msg.innerHTML = 'Kamera tidak bisa dibuka (' + esc(e.name || e) + ').<br>Izinkan akses kamera, atau gunakan 📷 / ⌨.';
      });
  }
  function stopKamera() {
    if (S.stream) { S.stream.getTracks().forEach(function (t) { t.stop(); }); S.stream = null; }
  }

  /** Perkecil gambar ke sisi terpanjang ±800 px, JPEG kualitas 0.72 (±80–150 KB). */
  function kompres(sumber, w, h) {
    var maks = 800, sk = Math.min(1, maks / Math.max(w, h));
    var c = document.createElement('canvas');
    c.width = Math.round(w * sk); c.height = Math.round(h * sk);
    c.getContext('2d').drawImage(sumber, 0, 0, c.width, c.height);
    return new Promise(function (res) { c.toBlob(function (b) { res({ blob: b, url: c.toDataURL('image/jpeg', 0.72) }); }, 'image/jpeg', 0.72); });
  }

  $$('#seg-jenis button').forEach(function (b) {
    b.addEventListener('click', function () {
      S.jenis = b.dataset.jenis;
      $$('#seg-jenis button').forEach(function (x) { x.classList.toggle('on', x === b); });
    });
  });

  $('#btn-foto').addEventListener('click', function () {
    var v = $('#cam');
    if (!S.stream || !v.videoWidth) return toast('Kamera belum siap', 'bad');
    getar(30);
    kompres(v, v.videoWidth, v.videoHeight).then(konfirmasiMasuk);
  });
  $('#file-foto').addEventListener('change', function (e) {
    var f = e.target.files[0]; e.target.value = '';
    if (!f) return;
    var img = new Image();
    img.onload = function () { kompres(img, img.naturalWidth, img.naturalHeight).then(konfirmasiMasuk); URL.revokeObjectURL(img.src); };
    img.src = URL.createObjectURL(f);
  });
  $('#btn-manual').addEventListener('click', function () { konfirmasiMasuk(null); });

  /** Layar konfirmasi plat sebelum tiket dicetak. foto = {blob, url} atau null. */
  function konfirmasiMasuk(foto) {
    var st = { foto: foto, fotoPath: null, ocr: null, kirim: false };
    var el = bukaSheet(
      (foto ? '<img class="photo" src="' + foto.url + '" alt="Foto kendaraan">' : '') +
      '<form id="f-masuk" class="stack" style="margin-top:12px" autocomplete="off">' +
      '<div class="ocr-state" id="ocr-state"></div>' +
      '<input class="plat-input" name="plat" placeholder="B 1234 ABC" maxlength="12" autocapitalize="characters" spellcheck="false" enterkeyhint="done">' +
      '<div class="seg" id="seg-jenis-2"><button type="button" data-jenis="motor">🏍 Motor</button><button type="button" data-jenis="mobil">🚗 Mobil</button></div>' +
      '<button class="btn primary xl" id="btn-cetak-tiket">🖨 Cetak Tiket</button>' +
      '<button type="button" class="btn ghost" id="btn-batal-masuk">' + (foto ? 'Foto ulang' : 'Batal') + '</button>' +
      '</form>');
    var f = $('#f-masuk', el), inp = f.plat, state = $('#ocr-state', el);
    function setJenis(j) { S.jenis = j; $$('#seg-jenis-2 button', el).concat($$('#seg-jenis button')).forEach(function (x) { x.classList.toggle('on', x.dataset.jenis === j); }); }
    setJenis(S.jenis);
    $$('#seg-jenis-2 button', el).forEach(function (b) { b.addEventListener('click', function () { setJenis(b.dataset.jenis); }); });
    inp.addEventListener('input', function () { var p = inp.selectionStart; inp.value = inp.value.toUpperCase(); try { inp.setSelectionRange(p, p); } catch (e) {} });
    $('#btn-batal-masuk', el).addEventListener('click', tutupSheet);

    function manual(pesan) {
      state.className = 'ocr-state warn'; state.textContent = pesan;
      inp.focus();
    }

    if (foto && S.config && S.config.ocr_aktif && S.online) {
      state.className = 'ocr-state'; state.innerHTML = '<span class="spin"></span> Membaca plat…';
      var fd = new FormData(); fd.append('foto', foto.blob, 'foto.jpg');
      var batas = ((S.config.ocr_timeout || 3) + 1.5) * 1000, selesai = false;
      var t = setTimeout(function () { if (!selesai) { selesai = true; manual('OCR terlalu lama — ketik plat manual'); } }, batas);
      api('ocr', { body: fd, timeout: batas + 4000 }).then(function (d) {
        st.fotoPath = d.foto;
        if (selesai) return;
        selesai = true; clearTimeout(t);
        if (d.plat && d.yakin !== false) {
          st.ocr = d.plat; inp.value = d.plat;
          state.className = 'ocr-state ok'; state.textContent = '✓ Terbaca otomatis — cocokkan dengan kendaraan';
          $('#btn-cetak-tiket', el).focus();
        } else if (d.plat) {
          // Hasil meragukan: tetap diisi sebagai saran, tapi petugas dipaksa memeriksa.
          st.ocr = d.plat; inp.value = d.plat;
          inp.classList.add('ragu');
          inp.addEventListener('input', function () { inp.classList.remove('ragu'); }, { once: true });
          getar([60, 40, 60]);
          manual('⚠ Periksa! OCR ragu (' + d.alasan + ') — cocokkan setiap huruf');
          inp.select();
        } else manual('Plat tidak terbaca — ketik manual');
      }).catch(function (e) { if (!selesai) { selesai = true; clearTimeout(t); manual(e.network ? 'Offline — ketik plat manual' : 'OCR gagal — ketik manual'); } });
    } else {
      manual(foto ? (S.online ? 'Ketik plat sesuai foto' : 'Mode offline — ketik plat manual') : 'Ketik plat nomor');
    }

    f.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (st.kirim) return;
      var plat = normPlat(inp.value);
      if (plat.replace(/ /g, '').length < 3) { inp.focus(); getar(150); return toast('Isi plat nomor dengan benar', 'bad'); }
      inp.value = plat;
      simpanMasuk(st, plat, false);
    });

    function simpanMasuk(st, plat, paksa) {
      st.kirim = true;
      var btn = $('#btn-cetak-tiket', el); btn.disabled = true; btn.textContent = 'Menyimpan…';
      var fd = new FormData();
      fd.append('plat', plat); fd.append('jenis', S.jenis);
      if (st.ocr) fd.append('plat_ocr', st.ocr);
      if (paksa) fd.append('paksa', '1');
      if (st.fotoPath) fd.append('foto', st.fotoPath);
      else if (st.foto) fd.append('foto', st.foto.blob, 'foto.jpg');
      api('masuk', { body: fd, timeout: 10000 }).then(function (t) {
        t.offline = false;
        selesaiMasuk(t);
      }).catch(function (e) {
        if (e.kode === 'duplikat') {
          st.kirim = false; btn.disabled = false; btn.textContent = '🖨 Cetak Tiket';
          getar([80, 50, 80]);
          if (confirm(e.message + '\n\nKemungkinan data keluar sebelumnya tidak tercatat. Tetap cetak tiket baru?')) simpanMasuk(st, plat, true);
          return;
        }
        if (e.network) return selesaiMasuk(tiketOffline(plat, st));
        st.kirim = false; btn.disabled = false; btn.textContent = '🖨 Cetak Tiket';
        toast(e.message, 'bad'); getar(200);
      });
    }
  }

  /** Buat tiket saat server tidak terjangkau; disimpan ke antrean sinkron. */
  function tiketOffline(plat, st) {
    var id = uuid(), waktu = new Date();
    var ts = Math.floor(waktu.getTime() / 1000).toString(36);
    var t = {
      id: id, kode_qr: 'PK1.' + id + '.' + (S.jenis === 'mobil' ? 'C' : 'M') + '.' + ts + '.OFF' + hexAcak(13),
      plat: plat, jenis: S.jenis, waktu_masuk: Tarif.fmt(waktu), pos_masuk: S.shift.pos_nama || 'Pos Masuk',
      petugas: S.petugas.nama, status: 'parkir', offline: true, no_tiket: Resi.noTiket(id)
    };
    Outbox.tambah({
      uid: 'm-' + id, tipe: 'masuk', id: id, kode_qr: t.kode_qr, plat: plat, jenis: S.jenis, waktu_masuk: t.waktu_masuk,
      pos_masuk: t.pos_masuk, shift_id: S.shift.id, foto_b64: st.foto ? st.foto.url : null, dibuat: Date.now()
    }).then(renderChips);
    return t;
  }

  function selesaiMasuk(t) {
    var key = 'm-' + t.id;
    catatRiwayat({ key: key, jenis: 'masuk', data: t, belumCetak: true });
    tutupSheet();
    bunyi(true); getar(60);
    tampilBukti('masuk', t, key, { auto: 5 });
  }

  // ================================================================ POS KELUAR
  function startScanner() {
    if (S.scanner || !window.Html5Qrcode) return;
    var msg = $('#scan-msg');
    if (!kameraTersedia()) {
      msg.hidden = false;
      msg.innerHTML = 'Scan langsung butuh HTTPS.<br>Gunakan "📷 Foto QR" atau ketik kode tiket.';
      return;
    }
    msg.hidden = true;
    var sc = new Html5Qrcode('reader', { verbose: false, formatsToSupport: window.Html5QrcodeSupportedFormats ? [Html5QrcodeSupportedFormats.QR_CODE] : undefined });
    S.scanner = sc;
    sc.start({ facingMode: 'environment' }, {
      fps: 12,
      qrbox: function (w, h) { var s = Math.floor(Math.min(w, h) * 0.72); return { width: s, height: s }; },
      aspectRatio: 0.75
    }, function (teks) {
      if (S.scanSibuk || !$('#sheet').hidden) return;
      S.scanSibuk = true;
      try { sc.pause(true); } catch (e) {}
      bunyi(true); getar(50);
      var fotoKeluar = ambilFrameScanner();
      prosesKode(teks, fotoKeluar);
    }, function () {}).catch(function (e) {
      S.scanner = null;
      msg.hidden = false;
      msg.innerHTML = 'Kamera tidak bisa dibuka (' + esc(e && e.name || e) + ').<br>Gunakan "📷 Foto QR" atau ketik kode.';
    });
  }
  function stopScanner() {
    var sc = S.scanner; S.scanner = null; S.scanSibuk = false;
    if (sc) { try { sc.stop().then(function () { sc.clear(); }).catch(function () {}); } catch (e) {} }
  }
  function lanjutScan() {
    S.scanSibuk = false;
    if (S.scanner) { try { S.scanner.resume(); } catch (e) {} }
  }
  /** Ambil foto kendaraan keluar dari frame kamera saat QR terbaca. */
  function ambilFrameScanner() {
    var v = $('#reader video');
    if (!v || !v.videoWidth) return Promise.resolve(null);
    return kompres(v, v.videoWidth, v.videoHeight).catch(function () { return null; });
  }

  $('#f-kode').addEventListener('submit', function (e) {
    e.preventDefault();
    var k = e.target.kode.value.trim(); if (!k) return;
    e.target.kode.value = '';
    S.scanSibuk = true; try { S.scanner && S.scanner.pause(true); } catch (er) {}
    prosesKode(k, ambilFrameScanner());
  });
  $('#file-qr').addEventListener('change', function (e) {
    var f = e.target.files[0]; e.target.value = '';
    if (!f) return;
    var el = document.createElement('div'); el.id = 'qr-file-tmp'; el.hidden = true; document.body.appendChild(el);
    var tmp = new Html5Qrcode('qr-file-tmp');
    tmp.scanFile(f, false).then(function (teks) {
      S.scanSibuk = true; try { S.scanner && S.scanner.pause(true); } catch (er) {}
      prosesKode(teks, null);
    }).catch(function () { toast('QR tidak terbaca dari foto', 'bad'); getar(200); }).then(function () { el.remove(); });
  });

  function urai(kode) {
    var p = String(kode).trim().split('.');
    if (p.length !== 5 || p[0] !== 'PK1' || (p[2] !== 'M' && p[2] !== 'C')) return null;
    return { id: p[1], jenis: p[2] === 'C' ? 'mobil' : 'motor', waktu_masuk: Tarif.fmt(new Date(parseInt(p[3], 36) * 1000)), kode: String(kode).trim() };
  }

  function prosesKode(kode, fotoP) {
    if (/^[0-9a-f]{4}-?[0-9a-f]{4}$/i.test(String(kode).trim())) return prosesNoTiket(String(kode).trim().toUpperCase(), fotoP);
    var u = urai(kode);
    if (!u) { bunyi(false); getar(300); toast('QR bukan tiket parkir ini', 'bad'); return setTimeout(lanjutScan, 1200); }
    api('keluar.scan', { body: { kode: u.kode }, timeout: 6000 }).then(function (d) {
      tampilHasil(d, fotoP, false);
    }).catch(function (e) {
      if (e.network) {
        if (load('qr_keluar', []).indexOf(u.kode) >= 0) {
          bunyi(false); getar(300); toast('Tiket ini sudah dipakai keluar', 'bad'); return setTimeout(lanjutScan, 1500);
        }
        var d;
        try { d = hitungOffline(u); } catch (er) { toast(er.message, 'bad', 4000); return setTimeout(lanjutScan, 1500); }
        return tampilHasil(d, fotoP, true);
      }
      bunyi(false); getar([200, 80, 200]);
      bukaSheet('<div class="stack"><div class="alert bad" style="font-size:17px">' + esc(e.message) + '</div>' +
        '<button class="btn warn xl" id="b-tt">Proses tanpa tiket / cari plat</button><button class="btn ghost" id="b-ok">Scan lagi</button></div>', lanjutScan);
      $('#b-ok').addEventListener('click', tutupSheet);
      $('#b-tt').addEventListener('click', function () { tutupSheet(); bukaCariPlat(''); });
    });
  }

  /** Nomor tiket yang diketik petugas (QR rusak). Butuh koneksi ke server. */
  function prosesNoTiket(no, fotoP) {
    api('keluar.scan', { body: { kode: no }, timeout: 6000 }).then(function (d) {
      tampilHasil(d, fotoP, false);
    }).catch(function (e) {
      bunyi(false); getar(300);
      toast(e.network ? 'Cari dengan nomor tiket butuh koneksi. Scan QR-nya atau tunggu online.' : e.message, 'bad', 4000);
      setTimeout(lanjutScan, 1500);
    });
  }

  function hitungOffline(u) {
    var t = Tarif.pilihTarif(S.config && S.config.tarif, u.jenis, u.waktu_masuk);
    if (!t) throw new Error('Tarif offline tidak tersedia');
    var waktu = sekarang();
    var r = Tarif.hitung(t, u.waktu_masuk, waktu, null, 0);
    return {
      tiket: { id: u.id, kode_qr: u.kode, plat: '', jenis: u.jenis, waktu_masuk: u.waktu_masuk, foto: null },
      waktu_keluar: waktu, tanpa_tiket: false, rincian: r, rincian_baris: Tarif.rincianBaris(r), total: r.total
    };
  }

  /** Layar hasil scan: data kendaraan + total + pilihan bayar. */
  function tampilHasil(d, fotoP, offline) {
    var t = d.tiket, dur = d.rincian.durasi_menit;
    var el = bukaSheet(
      (offline ? '<div class="alert" style="margin-bottom:12px">MODE OFFLINE — dihitung dengan tarif tersimpan. Plat & plat khusus tidak bisa dicek; isi plat dari kendaraan.</div>' : '') +
      (d.tanpa_tiket ? '<div class="alert bad" style="margin-bottom:12px">Keluar TANPA TIKET — wajib verifikasi STNK.</div>' : '') +
      (t.foto ? '<img class="photo" src="' + esc(t.foto) + '" alt="Foto saat masuk">' : '') +
      '<div class="stack" style="margin-top:12px">' +
      '<div class="row between">' + (offline ? '<input class="plat-input" id="plat-off" placeholder="PLAT" style="font-size:24px;min-height:56px">' : '<span class="plat lg">' + esc(t.plat) + '</span>') +
      '<span class="tag ' + (t.jenis === 'mobil' ? 'in' : 'out') + '" style="font-size:15px">' + esc(t.jenis.toUpperCase()) + '</span></div>' +
      (offline ? '' : '<p class="muted small" style="margin:0">Cocokkan plat & foto dengan kendaraan di depan Anda.</p>') +
      '<div class="info-grid"><div><small>Masuk</small><b>' + tglJam(t.waktu_masuk) + '</b></div><div><small>Durasi</small><b>' + Tarif.durasiTeks(dur) + '</b></div></div>' +
      '<div class="total-box"><div class="muted small" style="font-weight:700">TOTAL BAYAR</div><div class="big-total">' + rp(d.total) + '</div>' +
      (d.rincian.khusus ? '<div class="tag in">Plat ' + esc(d.rincian.khusus.tipe) + '</div>' : '') + '</div>' +
      '<details><summary class="muted">Rincian biaya</summary><table class="rincian">' +
      d.rincian_baris.map(function (b) { return '<tr><td>' + esc(b[0]) + '</td><td>' + rp(b[1]) + '</td></tr>'; }).join('') + '</table></details>' +
      (d.tanpa_tiket ? '<label class="field">Catatan verifikasi STNK (wajib)<input id="catatan-tt" placeholder="Nama di STNK / no. rangka"></label>' : '') +
      (d.total > 0
        ? '<div class="pay-grid"><button class="btn ok" data-bayar="cash">💵 Cash</button><button class="btn qris" data-bayar="qris" ' + (S.config && S.config.qris_gambar ? '' : 'title="Gambar QRIS belum diatur admin"') + '>▦ QRIS</button></div>'
        : '<button class="btn ok xl" data-bayar="cash">Keluarkan (gratis)</button>') +
      (!d.tanpa_tiket && !offline ? '<button class="btn ghost" id="b-tidak-sesuai">Kendaraan tidak sesuai?</button>' : '') +
      '<button class="btn ghost" id="b-batal">Batal</button></div>', lanjutScan);

    $('#b-batal', el).addEventListener('click', tutupSheet);
    var ts = $('#b-tidak-sesuai', el);
    if (ts) ts.addEventListener('click', function () { tutupSheet(); bukaCariPlat(''); });
    $$('[data-bayar]', el).forEach(function (b) {
      b.addEventListener('click', function () {
        var catatan = $('#catatan-tt', el) ? $('#catatan-tt', el).value.trim() : '';
        if (d.tanpa_tiket && !catatan) { toast('Isi catatan verifikasi STNK', 'bad'); return $('#catatan-tt', el).focus(); }
        var platOff = $('#plat-off', el);
        if (offline && platOff) t.plat = normPlat(platOff.value);
        if (b.dataset.bayar === 'cash') bayarCash(d, fotoP, offline, catatan);
        else bayarQris(d, fotoP, offline, catatan);
      });
    });
  }

  function bayarCash(d, fotoP, offline, catatan) {
    if (d.total === 0) return simpanKeluar(d, 'cash', fotoP, offline, catatan);
    var pecahan = [d.total, 5000, 10000, 20000, 50000, 100000].filter(function (x, i, a) { return x >= d.total && a.indexOf(x) === i; }).slice(0, 6);
    var el = bukaSheet('<div class="stack"><h3>Pembayaran cash</h3>' +
      '<div class="total-box"><div class="muted small" style="font-weight:700">TOTAL</div><div class="big-total">' + rp(d.total) + '</div></div>' +
      '<label class="field">Uang diterima<input id="uang" type="number" inputmode="numeric" min="0" step="500" placeholder="Opsional"></label>' +
      '<div class="quick">' + pecahan.map(function (x) { return '<button class="btn ghost" data-u="' + x + '">' + (x === d.total ? 'Uang pas' : rp(x)) + '</button>'; }).join('') + '</div>' +
      '<div class="row between"><span class="muted">Kembalian</span><span class="kembali" id="kembali">–</span></div>' +
      '<button class="btn ok xl" id="b-selesai">✓ Selesai & Cetak Resi</button><button class="btn ghost" id="b-kembali">Kembali</button></div>', lanjutScan);
    var uang = $('#uang', el);
    function hitungKembali() {
      var u = +uang.value, k = $('#kembali', el);
      if (!uang.value) { k.textContent = '–'; k.style.color = ''; return; }
      k.textContent = rp(u - d.total); k.style.color = u < d.total ? 'var(--danger)' : 'var(--ok)';
    }
    uang.addEventListener('input', hitungKembali);
    $$('[data-u]', el).forEach(function (b) { b.addEventListener('click', function () { uang.value = b.dataset.u; hitungKembali(); }); });
    $('#b-kembali', el).addEventListener('click', function () { S.sheetClose = null; tampilHasil(d, fotoP, offline); });
    $('#b-selesai', el).addEventListener('click', function () {
      if (uang.value && +uang.value < d.total) { getar(200); return toast('Uang diterima kurang dari total', 'bad'); }
      simpanKeluar(d, 'cash', fotoP, offline, catatan, uang.value ? +uang.value : null);
    });
  }

  function bayarQris(d, fotoP, offline, catatan) {
    var c = S.config || {};
    var el = bukaSheet('<div class="stack"><h3>Pembayaran QRIS</h3>' +
      (c.qris_gambar ? '<img class="qris-img" src="' + esc(c.qris_gambar) + '" alt="QRIS">' : '<div class="alert bad">Gambar QRIS belum diunggah admin.</div>') +
      (c.qris_merchant ? '<p class="center muted" style="margin:0">' + esc(c.qris_merchant) + '</p>' : '') +
      '<div class="total-box"><div class="muted small" style="font-weight:700">NOMINAL YANG HARUS DIBAYAR</div><div class="big-total">' + rp(d.total) + '</div></div>' +
      '<div class="alert">Tekan <b>Sudah Bayar</b> hanya setelah notifikasi pembayaran ' + rp(d.total) + ' masuk di HP/aplikasi merchant.</div>' +
      '<button class="btn qris xl" id="b-sudah">✓ Sudah Bayar</button><button class="btn ghost" id="b-kembali">Kembali</button></div>', lanjutScan);
    $('#b-kembali', el).addEventListener('click', function () { S.sheetClose = null; tampilHasil(d, fotoP, offline); });
    $('#b-sudah', el).addEventListener('click', function () {
      if (!confirm('Pembayaran QRIS ' + rp(d.total) + ' sudah masuk di aplikasi merchant?')) return;
      simpanKeluar(d, 'qris', fotoP, offline, catatan);
    });
  }

  function simpanKeluar(d, metode, fotoP, offline, catatan, tunai) {
    var btn = $('#sheet-body .xl'); if (btn) { btn.disabled = true; btn.textContent = 'Menyimpan…'; }
    Promise.resolve(fotoP).then(function (foto) {
      if (offline) return simpanKeluarOffline(d, metode, foto, catatan);
      var fd = new FormData();
      fd.append('id', d.tiket.id); fd.append('metode', metode); fd.append('waktu_keluar', d.waktu_keluar);
      if (d.tanpa_tiket) { fd.append('tanpa_tiket', '1'); fd.append('catatan', catatan); }
      if (foto && foto.blob) fd.append('foto', foto.blob, 'keluar.jpg');
      return api('keluar.bayar', { body: fd, timeout: 12000 }).then(function (r) { r.offline = false; return r; }, function (e) {
        if (e.network && !d.tanpa_tiket) return simpanKeluarOffline(d, metode, foto, catatan);
        throw e;
      });
    }).then(function (resi) {
      var q = load('qr_keluar', []); q.unshift(d.tiket.kode_qr); save('qr_keluar', q.slice(0, 500));
      var key = 'k-' + d.tiket.id;
      if (metode === 'cash' && tunai && tunai >= resi.total) resi.tunai = tunai;
      catatRiwayat({ key: key, jenis: 'keluar', data: resi, belumCetak: true });
      S.sheetClose = null; tutupSheet();
      bunyi(true); getar(60);
      tampilBukti('keluar', resi, key, { auto: 6, onSelesai: lanjutScan });
    }).catch(function (e) {
      if (btn) { btn.disabled = false; btn.textContent = 'Coba lagi'; }
      toast(e.message, 'bad', 4000); getar(200);
    });
  }

  function simpanKeluarOffline(d, metode, foto, catatan) {
    var resi = {
      id: null, plat: d.tiket.plat || '', jenis: d.tiket.jenis, waktu_masuk: d.tiket.waktu_masuk, waktu_keluar: d.waktu_keluar,
      durasi_menit: d.rincian.durasi_menit, total: d.total, metode: metode, tanpa_tiket: false, petugas: S.petugas.nama,
      rincian: d.rincian_baris, offline: true, no_tiket: Resi.noTiket(d.tiket.id), pos_keluar: S.shift.pos_nama
    };
    return Outbox.tambah({
      uid: 'k-' + d.tiket.id, tipe: 'keluar', kode_qr: d.tiket.kode_qr, metode: metode, waktu_keluar: d.waktu_keluar,
      total: d.total, catatan: (resi.plat ? 'Plat (offline): ' + resi.plat + '. ' : '') + (catatan || ''),
      shift_id: S.shift.id, foto_b64: foto ? foto.url : null, dibuat: Date.now()
    }).then(function () { renderChips(); return resi; });
  }

  // ---------------------------------------------------------------- tanpa tiket
  $('#btn-tanpa-tiket').addEventListener('click', function () {
    S.scanSibuk = true; try { S.scanner && S.scanner.pause(true); } catch (e) {}
    bukaCariPlat('');
  });

  function bukaCariPlat(awal) {
    if (!S.online) { toast('Pencarian plat butuh koneksi ke server', 'bad'); return lanjutScan(); }
    var el = bukaSheet('<div class="stack"><h3>Cari kendaraan berdasarkan plat</h3>' +
      '<p class="muted small" style="margin:0">Untuk tiket hilang atau kendaraan tidak sesuai data QR. Denda tanpa tiket akan ditambahkan.</p>' +
      '<input class="plat-input" id="cari-plat" placeholder="B 1234" value="' + esc(awal) + '" autocapitalize="characters" style="font-size:26px;min-height:58px">' +
      '<div id="hasil-cari" class="list"></div><button class="btn ghost" id="b-tutup">Tutup</button></div>', lanjutScan);
    var inp = $('#cari-plat', el), timer;
    inp.focus();
    $('#b-tutup', el).addEventListener('click', tutupSheet);
    inp.addEventListener('input', function () {
      inp.value = inp.value.toUpperCase();
      clearTimeout(timer);
      timer = setTimeout(cari, 350);
    });
    function cari() {
      var q = inp.value.replace(/\s/g, '');
      var box = $('#hasil-cari', el);
      if (q.length < 2) { box.innerHTML = ''; return; }
      box.innerHTML = '<div class="empty"><span class="spin"></span></div>';
      api('keluar.cari', { query: '&plat=' + encodeURIComponent(q) }).then(function (rows) {
        if (!rows.length) { box.innerHTML = '<div class="empty">Tidak ada kendaraan berstatus parkir dengan plat tersebut.</div>'; return; }
        box.innerHTML = rows.map(function (r) {
          return '<button class="item result-item" data-id="' + esc(r.id) + '">' + (r.foto ? '<img src="' + esc(r.foto) + '" alt="">' : '<img alt="">') +
            '<span><span class="plat">' + esc(r.plat) + '</span><br><small class="muted">' + esc(r.jenis) + ' · masuk ' + tglJam(r.waktu_masuk) + ' · ' + esc(r.pos_masuk) + '</small></span></button>';
        }).join('');
      }).catch(function (e) { box.innerHTML = '<div class="alert bad">' + esc(e.message) + '</div>'; });
    }
    $('#hasil-cari', el).addEventListener('click', function (e) {
      var b = e.target.closest('[data-id]'); if (!b) return;
      api('keluar.hitung', { body: { id: b.dataset.id, tanpa_tiket: 1 } }).then(function (d) {
        S.sheetClose = null;
        tampilHasil(d, ambilFrameScanner(), false);
      }).catch(function (er) { toast(er.message, 'bad'); });
    });
    if (awal) cari();
  }

  // ================================================================ SHIFT
  function renderShift() {
    var info = $('#shift-info');
    var s = S.shift;
    info.innerHTML = '<span class="k">Pos</span><span class="v">' + esc(s.pos === 'masuk' ? 'Masuk' : 'Keluar') + ' · ' + esc(s.pos_nama) + '</span>' +
      '<span class="k">Dibuka</span><span class="v">' + tglJam(s.waktu_buka) + '</span><span class="k">Ringkasan</span><span class="v muted">memuat…</span>';
    $('#f-tutup').setor.value = '';
    $('#selisih-preview').textContent = '';
    api('shift.ringkasan').then(function (d) {
      S.ringkasan = d;
      info.innerHTML = '<span class="k">Pos</span><span class="v">' + esc(s.pos === 'masuk' ? 'Masuk' : 'Keluar') + ' · ' + esc(s.pos_nama) + '</span>' +
        '<span class="k">Dibuka</span><span class="v">' + tglJam(s.waktu_buka) + '</span>' +
        (s.pos === 'masuk' ? '<span class="k">Kendaraan masuk</span><span class="v">' + d.kendaraan_masuk + '</span>'
          : '<span class="k">Kendaraan keluar</span><span class="v">' + d.kendaraan_keluar + '</span>' +
            '<span class="k">QRIS</span><span class="v">' + rp(d.total_qris) + '</span>' +
            '<span class="k">Cash (harus disetor)</span><span class="v" style="font-size:20px">' + rp(d.total_cash) + '</span>');
    }).catch(function (e) {
      info.lastElementChild.textContent = e.network ? 'offline' : e.message;
    });
    renderPrinter();
    renderSync();
  }
  $('#btn-refresh-shift').addEventListener('click', renderShift);
  $('#f-tutup').setor.addEventListener('input', function (e) {
    var p = $('#selisih-preview');
    if (!S.ringkasan || e.target.value === '') { p.textContent = ''; return; }
    var sl = +e.target.value - S.ringkasan.total_cash;
    p.innerHTML = sl === 0 ? '<b style="color:var(--ok)">Setoran pas</b>' : 'Selisih: <b style="color:' + (sl < 0 ? 'var(--danger)' : 'var(--warn)') + '">' + (sl > 0 ? '+' : '') + rp(sl) + '</b>';
  });
  $('#f-tutup').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target;
    Outbox.jumlah().then(function (n) {
      if (n) { toast('Masih ada ' + n + ' transaksi offline. Sinkronkan dulu sebelum menutup shift.', 'bad', 4000); return; }
      if (!confirm('Tutup shift dengan setoran ' + rp(f.setor.value) + '?')) return;
      api('shift.tutup', { body: { setor: +f.setor.value, catatan: f.catatan.value } }).then(function (d) {
        stopKamera(); stopScanner();
        var sh = d.shift;
        bukaSheet('<div class="stack"><h3>Shift ditutup</h3><div class="kv">' +
          '<span class="k">Cash sistem</span><span class="v">' + rp(sh.total_cash_sistem) + '</span>' +
          '<span class="k">Disetor</span><span class="v">' + rp(sh.total_cash_setor) + '</span>' +
          '<span class="k">Selisih</span><span class="v" style="color:' + (sh.selisih < 0 ? 'var(--danger)' : 'inherit') + '">' + rp(sh.selisih) + '</span>' +
          '<span class="k">Masuk / keluar</span><span class="v">' + d.kendaraan_masuk + ' / ' + d.kendaraan_keluar + '</span></div>' +
          '<button class="btn primary xl" id="b-ok">Selesai</button></div>');
        $('#b-ok').addEventListener('click', tutupSheet);
        Printer.berhentiJaga(true);
        S.shift = null; simpanSesi(); f.reset();
        masukAplikasi();
      }).catch(function (er) { toast(er.message, 'bad'); });
    });
  });

  // ---------------------------------------------------------------- printer
  function renderPrinter() {
    var sel = $('#sel-printer'); sel.value = Printer.mode();
    var st = Printer.status(), info = $('#printer-info');
    $('#btn-pilih-printer').hidden = st.mode !== 'bluetooth';
    $('#opsi-gambar').hidden = st.mode !== 'bluetooth' && st.mode !== 'rawbt';
    $('#opsi-paket').hidden = st.mode !== 'bluetooth';
    $('#cek-gambar').checked = Printer.sebagaiGambar();
    $('#sel-paket').value = String(Printer.ukuranPaket());
    $('#opsi-qr').hidden = st.mode === 'none';
    $('#sel-kertas').value = Printer.kertasPerangkat();
    var kAdmin = Resi.kertas(Resi.opsi(S.config || {}).kertas);
    $('#sel-kertas').options[0].textContent = 'Ikuti pengaturan admin (' + kAdmin.label + ')';
    $('#sel-qr').value = String(Printer.qrTipis());
    info.textContent = st.mode === 'bluetooth'
      ? (navigator.bluetooth ? (st.connected ? 'Tersambung: ' + (st.name || 'printer') + '. Koneksi dijaga otomatis sampai shift ditutup (sambung ulang otomatis bila terputus).' : st.connecting ? 'Menyambung ulang ke ' + (st.name || 'printer') + '…' : 'Belum tersambung. Nyalakan printer lalu tekan Hubungkan printer. Bila printer tidak muncul di daftar, printer hanya Bluetooth klasik: pakai mode RawBT.') : 'Browser ini tidak mendukung Web Bluetooth (butuh Chrome Android + HTTPS). Pilih mode RawBT.')
      : st.mode === 'rawbt' ? 'Pasang aplikasi RawBT dari Play Store, pasangkan printer di dalamnya. Paling andal untuk printer Bluetooth klasik.'
      : st.mode === 'browser' ? 'Tiket dicetak sebagai gambar lewat dialog cetak Android. Pilih ukuran kertas 58 mm / roll bila tersedia.' : 'Resi tidak dicetak (hanya untuk uji coba).';
  }
  $('#sel-printer').addEventListener('change', function (e) { Printer.setMode(e.target.value); renderPrinter(); jagaPrinter(); });
  $('#bp-aksi').addEventListener('click', hubungkanPrinter);
  $('#cek-gambar').addEventListener('change', function (e) { Printer.setSebagaiGambar(e.target.checked); });
  $('#sel-paket').addEventListener('change', function (e) { Printer.setUkuranPaket(e.target.value); });
  $('#sel-qr').addEventListener('change', function (e) { Printer.setQrTipis(e.target.value); });
  $('#sel-kertas').addEventListener('change', function (e) { Printer.setKertasPerangkat(e.target.value); renderPrinter(); });
  $('#btn-pilih-printer').addEventListener('click', function () {
    Printer.pilih().then(function () {
      toast('Printer tersambung', 'ok'); renderPrinter();
      if (S.shift) Printer.mulaiJaga().catch(function () {});
    }).catch(function (e) { if (e && e.name !== 'NotFoundError') toast(e.message, 'bad', 4000); });
  });
  $('#btn-tes-printer').addEventListener('click', function () {
    Printer.tesCetak(S.config || {}).then(function () { toast('Tes cetak dikirim', 'ok'); renderPrinter(); }).catch(function (e) { toast(e.message, 'bad', 4000); });
  });

  // ---------------------------------------------------------------- sinkronisasi
  var sedangSinkron = false;
  function sinkron() {
    if (sedangSinkron || !S.token) return Promise.resolve();
    return Outbox.semua().then(function (items) {
      if (!items.length) return;
      sedangSinkron = true;
      // Tiket masuk dulu agar tiket keluar yang merujuknya bisa diproses dalam batch yang sama.
      items.sort(function (a, b) { return (a.tipe === 'masuk' ? 0 : 1) - (b.tipe === 'masuk' ? 0 : 1) || a.dibuat - b.dibuat; });
      var batch = items.slice(0, 20);
      return api('sync', { body: { items: batch }, timeout: 30000 }).then(function (hasil) {
        return Promise.all(hasil.map(function (h) {
          var it = batch.filter(function (x) { return x.uid === h.uid; })[0];
          if (!it) return null;
          if (h.ok) return Outbox.hapus(h.uid);
          if (h.kode === 'menunggu') return null;
          return Outbox.tandaiGagal(it, h.error);
        }));
      }).then(function () {
        sedangSinkron = false;
        renderChips();
        if (!$('#s-shift').hidden) renderSync();
        if (items.length > batch.length) return sinkron();
      }, function () { sedangSinkron = false; });
    }).catch(function () { sedangSinkron = false; });
  }
  setInterval(function () { if (S.token) { if (S.online) sinkron(); else api('me').catch(function () {}); } }, 20000);

  function renderSync() {
    Outbox.jumlah().then(function (n) {
      $('#sync-info').textContent = n ? n + ' transaksi menunggu dikirim ke server.' : 'Semua transaksi sudah tersinkron.';
    });
    Outbox.gagal().then(function (g) {
      $('#sync-gagal').innerHTML = g.map(function (x) {
        return '<div class="item"><div class="row between"><span class="tag bad">Ditolak server</span><span class="small muted">' + esc(x.tipe) + '</span></div>' +
          '<div class="small">' + esc(x.plat || x.kode_qr) + ' — ' + esc(x.error) + '</div>' +
          '<button class="btn ghost sm" data-hapus-gagal="' + esc(x.uid) + '">Sudah dilaporkan ke admin, hapus</button></div>';
      }).join('');
    });
  }
  $('#btn-sync').addEventListener('click', function () {
    api('me').then(sinkron).then(function () { toast('Sinkronisasi selesai', 'ok'); renderSync(); }).catch(function (e) { toast(e.message, 'bad'); });
  });
  $('#sync-gagal').addEventListener('click', function (e) {
    var b = e.target.closest('[data-hapus-gagal]'); if (!b) return;
    Outbox.hapusGagal(b.dataset.hapusGagal).then(renderSync);
  });

  // ================================================================ start
  // ================================================================ versi & pembaruan
  function teksVersi(v, tgl) {
    var d = tgl ? new Date(tgl + 'T00:00:00') : null;
    return 'ParkIt ' + v + (d && !isNaN(d) ? ' · ' + d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' }) : '');
  }
  $('#ver-login').textContent = teksVersi(VERSI_APP, window.PARKIT_TANGGAL);
  $('#ver-shift').textContent = teksVersi(VERSI_APP, window.PARKIT_TANGGAL);

  var versiBaru = null;
  /** Aman memuat ulang: tidak ada lembar (pembayaran/konfirmasi) terbuka dan tidak sedang menyimpan. */
  function amanMuatUlang() { return $('#sheet').hidden; }
  function nomorVersi(v) { var n = parseInt(String(v || "").replace(/\D/g, ""), 10); return isNaN(n) ? 0 : n; }
  function adaVersiBaru(v) {
    if (!v || nomorVersi(v) <= nomorVersi(VERSI_APP)) return; // hanya versi yang lebih baru
    versiBaru = v;
    // Belum bekerja (login / pilih pos) atau aplikasi di latar belakang: langsung pakai versi baru.
    if (!$('#app').hidden && document.visibilityState === 'visible') {
      $('#bu-teks').textContent = 'Versi baru ' + v + ' tersedia (sekarang ' + VERSI_APP + ').';
      $('#banner-update').hidden = false;
    } else if (amanMuatUlang()) {
      location.reload();
    }
  }
  $('#bu-aksi').addEventListener('click', function () {
    if (!amanMuatUlang()) return toast('Selesaikan transaksi yang terbuka dulu, lalu muat ulang.', 'bad');
    location.reload();
  });
  document.addEventListener('visibilitychange', function () {
    // Kembali dari latar belakang dengan versi baru menunggu: muat ulang otomatis bila aman.
    if (document.visibilityState === 'visible' && versiBaru && amanMuatUlang()) location.reload();
  });

  if ('serviceWorker' in navigator) {
    var punyaController = !!navigator.serviceWorker.controller;
    navigator.serviceWorker.register('sw.js', { updateViaCache: 'none' }).then(function (reg) {
      // Periksa pembaruan berkala & saat aplikasi kembali dibuka.
      setInterval(function () { if (S.online) reg.update().catch(function () {}); }, 10 * 60 * 1000);
      document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') reg.update().catch(function () {}); });
    }).catch(function () {});
    navigator.serviceWorker.addEventListener('message', function (e) { if (e.data && e.data.versi) adaVersiBaru(e.data.versi); });
    navigator.serviceWorker.addEventListener('controllerchange', function () {
      // Service worker versi baru mengambil alih (pemasangan pertama tidak dihitung).
      if (!punyaController) { punyaController = true; return; }
      if (navigator.serviceWorker.controller) navigator.serviceWorker.controller.postMessage('versi');
    });
    // Jaga-jaga: aplikasi dibuka dari cache lama sementara service worker sudah versi baru.
    if (navigator.serviceWorker.controller) navigator.serviceWorker.controller.postMessage('versi');
  }
  var sesi0 = load('sesi', null);
  if (sesi0 && sesi0.petugas) $('#f-login').username.value = sesi0.petugas.username || '';
  if (!window.isSecureContext) $('#login-net').textContent = 'Akses tanpa HTTPS: kamera langsung & printer Bluetooth tidak tersedia.';
  mulai();
  setTimeout(function () { if (S.token) sinkron(); }, 1500);
})();
