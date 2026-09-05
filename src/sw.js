/*
 * Service worker for the growth tracker.
 *
 * Scope is deliberately narrow: only the static shell is cached, and every
 * request for a page or an export goes to the network untouched. This area
 * holds children's health measurements, and stashing rendered pages in a cache
 * would leave that data sitting on the device after the user thought they had
 * navigated away from it. The cost is that charts are not viewable offline,
 * which is a fair trade for a tool used at home and at the paediatrician.
 *
 * Its real job is making the app open instantly from the home screen. iOS does
 * not need a service worker to install a PWA at all - the manifest is enough.
 */

var CACHE = 'rust-shell-v1';

var SHELL = [
  'rust.css',
  'rust.js',
  'manifest.json',
  'icon-180.png',
  'icon-192.png',
  'icon-512.png'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE).then(function (cache) {
      return cache.addAll(SHELL);
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (names) {
      return Promise.all(names.map(function (name) {
        return name === CACHE ? null : caches.delete(name);
      }));
    }).then(function () {
      return self.clients.claim();
    })
  );
});

self.addEventListener('fetch', function (event) {
  var request = event.request;

  if (request.method !== 'GET') {
    return;
  }

  var url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  /* Only the static shell is served from the cache. Anything that can carry
     measurements - every .php page, every export - is left to the network so
     that nothing personal is retained here. */
  var isShell = SHELL.some(function (name) {
    return url.pathname.endsWith('/' + name);
  });
  if (!isShell) {
    return;
  }

  event.respondWith(
    caches.match(request).then(function (cached) {
      return cached || fetch(request);
    })
  );
});
