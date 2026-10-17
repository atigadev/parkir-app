/* Service worker: app shell bisa dibuka tanpa koneksi. API tidak pernah di-cache. */
var VERSI = 'parkit-v6';
var SHELL = [
  './', 'index.html', 'app.css?v=6', 'manifest.webmanifest',
  'js/app.js?v=6', 'js/tarif.js', 'js/resi.js?v=6', 'js/printer.js?v=6', 'js/outbox.js',
  'vendor/qrcode.js', 'vendor/html5-qrcode.min.js',
  'icons/icon.svg', 'icons/icon-192.png', 'icons/icon-512.png'
];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(VERSI).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== VERSI; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request, url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;
  if (url.pathname.indexOf('/api/') >= 0 || url.pathname.indexOf('/uploads/foto/') >= 0) return;

  // Gambar QRIS: pakai cache bila offline.
  // App shell: stale-while-revalidate — tampil cepat dari cache, diperbarui di belakang.
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
