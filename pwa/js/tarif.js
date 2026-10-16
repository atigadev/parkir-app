/**
 * Port mesin tarif dari inc/tarif.php untuk pos keluar saat offline.
 * Harus identik dengan versi PHP — uji dengan tests/tarif_parity.html.
 */
(function (global) {
  'use strict';

  function parse(s) {
    var m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
    if (!m) return new Date(s);
    return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +(m[6] || 0));
  }

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function fmt(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' +
      pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }

  function addMinutes(d, n) { return new Date(d.getTime() + n * 60000); }

  function nextMidnight(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() + 1, 0, 0, 0); }

  function hitung(tarif, masuk, keluar, khusus, denda) {
    denda = denda || 0;
    var tMasuk = parse(masuk), tKeluar = parse(keluar);
    if (tKeluar < tMasuk) tKeluar = tMasuk;
    var durasi = Math.floor((Math.floor(tKeluar.getTime() / 1000) - Math.floor(tMasuk.getTime() / 1000)) / 60);
    var toleransi = +tarif.toleransi_menit;
    var efektif = Math.max(0, durasi - toleransi);

    var hasil = {
      tarif_id: +tarif.id,
      jenis: tarif.jenis_kendaraan,
      tarif: {
        durasi_awal_menit: +tarif.durasi_awal_menit,
        biaya_awal: +tarif.biaya_awal,
        biaya_per_jam: +tarif.biaya_per_jam,
        maks_per_hari: +tarif.maks_per_hari,
        biaya_menginap: +tarif.biaya_menginap,
        jam_batas_menginap: String(tarif.jam_batas_menginap).slice(0, 5),
        toleransi_menit: toleransi
      },
      waktu_masuk: fmt(tMasuk),
      waktu_keluar: fmt(tKeluar),
      durasi_menit: durasi,
      durasi_efektif: efektif,
      khusus: null,
      hari: [],
      malam: 0,
      biaya_menginap: 0,
      subtotal: 0,
      denda: denda,
      total: denda
    };

    if (khusus) {
      hasil.khusus = { tipe: khusus.tipe, keterangan: khusus.keterangan || null };
      return hasil;
    }
    if (efektif <= 0) return hasil;

    var awal = +tarif.durasi_awal_menit, bAwal = +tarif.biaya_awal, perJam = +tarif.biaya_per_jam, maks = +tarif.maks_per_hari;
    var cursor = addMinutes(tMasuk, toleransi);
    var subtotal = 0;
    while (cursor < tKeluar) {
      var tm = nextMidnight(cursor);
      var akhir = tm < tKeluar ? tm : tKeluar;
      var menit = Math.floor((Math.floor(akhir.getTime() / 1000) - Math.floor(cursor.getTime() / 1000)) / 60);
      if (menit > 0) {
        var jamTambahan = menit > awal ? Math.ceil((menit - awal) / 60) : 0;
        var biaya = bAwal + jamTambahan * perJam;
        var kenaMaks = maks > 0 && biaya > maks;
        if (kenaMaks) biaya = maks;
        hasil.hari.push({
          tanggal: fmt(cursor).slice(0, 10), menit: menit, jam_tambahan: jamTambahan, biaya: biaya, kena_maks: kenaMaks
        });
        subtotal += biaya;
      }
      cursor = akhir;
    }

    var malam = 0;
    if (+tarif.biaya_menginap > 0) {
      var hm = String(tarif.jam_batas_menginap).split(':');
      var t = new Date(tMasuk.getFullYear(), tMasuk.getMonth(), tMasuk.getDate(), +hm[0], +hm[1], 0);
      if (t <= tMasuk) t = new Date(t.getFullYear(), t.getMonth(), t.getDate() + 1, +hm[0], +hm[1], 0);
      while (t <= tKeluar) {
        malam++;
        t = new Date(t.getFullYear(), t.getMonth(), t.getDate() + 1, +hm[0], +hm[1], 0);
      }
    }

    hasil.malam = malam;
    hasil.biaya_menginap = malam * +tarif.biaya_menginap;
    hasil.subtotal = subtotal;
    hasil.total = subtotal + hasil.biaya_menginap + denda;
    return hasil;
  }

  function durasiTeks(menit) {
    var h = Math.floor(menit / 1440), j = Math.floor((menit % 1440) / 60), m = menit % 60, out = [];
    if (h) out.push(h + ' hr');
    if (j) out.push(j + ' j');
    out.push(m + ' m');
    return out.join(' ');
  }

  function rincianBaris(r) {
    var b = [];
    if (r.khusus) b.push(['Plat khusus (' + r.khusus.tipe + ')', 0]);
    else if (r.durasi_efektif <= 0) b.push(['Dalam toleransi ' + r.tarif.toleransi_menit + ' mnt', 0]);
    var multi = r.hari.length > 1;
    r.hari.forEach(function (h) {
      var label = multi ? h.tanggal.slice(8, 10) + '/' + h.tanggal.slice(5, 7) + ' ' : '';
      b.push([label + durasiTeks(h.menit) + (h.kena_maks ? ' (maks)' : ''), h.biaya]);
    });
    if (r.malam > 0) b.push(['Menginap x' + r.malam, r.biaya_menginap]);
    if (r.denda > 0) b.push(['Denda tanpa tiket', r.denda]);
    return b;
  }

  /** Pilih versi tarif aktif terbaru yang berlaku pada waktu masuk. */
  function pilihTarif(daftar, jenis, waktuMasuk) {
    var t = parse(waktuMasuk), pilih = null;
    (daftar || []).forEach(function (x) {
      if (x.jenis_kendaraan !== jenis || +x.aktif !== 1 || parse(x.berlaku_mulai) > t) return;
      if (!pilih || parse(x.berlaku_mulai) > parse(pilih.berlaku_mulai) ||
          (+parse(x.berlaku_mulai) === +parse(pilih.berlaku_mulai) && +x.id > +pilih.id)) pilih = x;
    });
    return pilih;
  }

  global.Tarif = { hitung: hitung, rincianBaris: rincianBaris, pilihTarif: pilihTarif, durasiTeks: durasiTeks, parse: parse, fmt: fmt };
})(typeof window !== 'undefined' ? window : this);
