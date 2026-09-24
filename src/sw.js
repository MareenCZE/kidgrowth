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

/* Bumped when the cached asset names change: an installed PWA holds the old
   shell until the cache name differs, so renaming rust.css/rust.js without
   this would keep serving files that no longer exist. */
var CACHE = 'growth-shell-v2';

var SHELL = [
  'growth.css',
  'growth.js',
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
      // The Cache Storage is shared by everything on the origin, so delete
      // only this app's own old versions ('rust-shell-' is the pre-v2 name).
      return Promise.all(names.filter(function (name) {
        return name !== CACHE &&
          (name.indexOf('growth-shell-') === 0 || name.indexOf('rust-shell-') === 0);
      }).map(function (name) {
        return caches.delete(name);
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
