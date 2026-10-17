/* Service worker: app shell bisa dibuka tanpa koneksi. API tidak pernah di-cache. */
importScripts('versi.js?v=9');
var VERSI = 'parkit-' + self.PARKIT_VERSI;
var SHELL = [
  './', 'index.html', 'versi.js?v=9', 'struk.css?v=9', 'app.css?v=9', 'manifest.webmanifest',
  'js/app.js?v=9', 'js/tarif.js', 'js/resi.js?v=9', 'js/printer.js?v=9', 'js/outbox.js',
  'vendor/qrcode.js', 'vendor/html5-qrcode.min.js',
  'icons/icon.svg', 'icons/icon-192.png', 'icons/icon-512.png'
];

self.addEventListener('install', function (e) {
  // cache: 'reload' = ambil langsung dari server, bukan dari cache HTTP browser yang mungkin masih versi lama.
  e.waitUntil(caches.open(VERSI).then(function (c) {
    return c.addAll(SHELL.map(function (u) { return new Request(u, { cache: 'reload' }); }));
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== VERSI; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

// Halaman aplikasi bertanya versi service worker yang aktif.
self.addEventListener('message', function (e) {
  if (e.data === 'versi' && e.source) e.source.postMessage({ versi: self.PARKIT_VERSI, tanggal: self.PARKIT_TANGGAL });
});

self.addEventListener('fetch', function (e) {
  var req = e.request, url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;
  if (url.pathname.indexOf('/api/') >= 0 || url.pathname.indexOf('/uploads/foto/') >= 0) return;

  // Halaman utama: ambil dari server dulu (agar versi baru langsung terpakai), cache hanya cadangan offline.
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).then(function (res) {
      if (res && res.ok) { var salinan = res.clone(); caches.open(VERSI).then(function (c) { c.put('index.html', salinan); }); }
      return res;
    }).catch(function () {
      return caches.match('index.html').then(function (hit) { return hit || caches.match('./'); });
    }));
    return;
  }

  // Aset (sudah berversi lewat ?v=) & gambar QRIS: dari cache, diperbarui di belakang (stale-while-revalidate).
  e.respondWith(caches.open(VERSI).then(function (cache) {
    return cache.match(req, { ignoreSearch: url.pathname.indexOf('/uploads/qris/') >= 0 }).then(function (hit) {
      var net = fetch(req).then(function (res) {
        if (res && res.ok) cache.put(req, res.clone());
        return res;
      }).catch(function () { return hit || caches.match('index.html'); });
      return hit || net;
    });
  }));
});
