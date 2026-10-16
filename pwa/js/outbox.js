/**
 * Antrean transaksi offline di IndexedDB. Setiap item: {uid, tipe: 'masuk'|'keluar', ...data, dibuat}
 * Disinkron ke api ?r=sync saat online; item yang ditolak server dipindah ke daftar "gagal".
 */
(function (global) {
  'use strict';
  var DB = 'parkit', VER = 1, dbp = null;

  function open() {
    if (dbp) return dbp;
    dbp = new Promise(function (res, rej) {
      var r = indexedDB.open(DB, VER);
      r.onupgradeneeded = function () {
        var d = r.result;
        if (!d.objectStoreNames.contains('outbox')) d.createObjectStore('outbox', { keyPath: 'uid' });
        if (!d.objectStoreNames.contains('gagal')) d.createObjectStore('gagal', { keyPath: 'uid' });
      };
      r.onsuccess = function () { res(r.result); };
      r.onerror = function () { rej(r.error); };
    });
    return dbp;
  }

  function tx(store, mode, fn) {
    return open().then(function (d) {
      return new Promise(function (res, rej) {
        var t = d.transaction(store, mode), s = t.objectStore(store), out;
        var req = fn(s);
        if (req) req.onsuccess = function () { out = req.result; };
        t.oncomplete = function () { res(out); };
        t.onerror = function () { rej(t.error); };
      });
    });
  }

  global.Outbox = {
    tambah: function (item) { return tx('outbox', 'readwrite', function (s) { return s.put(item); }); },
    semua: function () { return tx('outbox', 'readonly', function (s) { return s.getAll(); }); },
    hapus: function (uid) { return tx('outbox', 'readwrite', function (s) { return s.delete(uid); }); },
    jumlah: function () { return tx('outbox', 'readonly', function (s) { return s.count(); }); },
    tandaiGagal: function (item, error) {
      item.error = error;
      return tx('gagal', 'readwrite', function (s) { return s.put(item); }).then(function () { return global.Outbox.hapus(item.uid); });
    },
    gagal: function () { return tx('gagal', 'readonly', function (s) { return s.getAll(); }); },
    hapusGagal: function (uid) { return tx('gagal', 'readwrite', function (s) { return s.delete(uid); }); }
  };
})(window);
