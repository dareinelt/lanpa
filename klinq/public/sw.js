/* Service Worker: App-Shell-Cache für den mobilen Bereich; API-Aufrufe werden nicht gecacht. */
const CACHE_NAME = "klinq-shell-v5";
const SHELL = [
    "/m",
    "/css/app.css",
    "/js/ui.js",
    "/js/pwa.js",
    "/js/offline.js",
    "/js/offline-ui.js",
    "/js/mobile.js",
    "/js/picker.js",
    "/js/scan.js",
    "/js/search.js",
    "/js/vendor/jsqr.js",
    "/img/icon.svg",
    "/img/icon-192.png",
    "/img/icon-512.png",
    "/manifest.webmanifest",
    "/offline.html"
];

self.addEventListener("install", function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function (cache) {
            return Promise.allSettled(SHELL.map(function (url) { return cache.add(url); }));
        }).then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener("activate", function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (k) { return k !== CACHE_NAME; }).map(function (k) { return caches.delete(k); }));
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener("fetch", function (event) {
    const request = event.request;
    if (request.method !== "GET") { return; }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) { return; }
    if (url.pathname.startsWith("/api/") || url.pathname === "/login" || url.pathname === "/logout") { return; }

    // Statische Dateien: Netz zuerst (Versions-Query ?v= bleibt wirksam), im Cache ohne Query
    // abgelegt, damit offline immer die zuletzt gesehene Version ausgeliefert wird.
    if (/\.(css|js|svg|png|webmanifest|woff2?)$/.test(url.pathname)) {
        const cacheKey = url.origin + url.pathname;
        event.respondWith(
            fetch(request).then(function (response) {
                if (response.ok && response.type === "basic") {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then(function (cache) { cache.put(cacheKey, copy); });
                }
                return response;
            }).catch(function () {
                return caches.match(cacheKey);
            })
        );
        return;
    }

    // Seiten des mobilen Bereichs: Netz zuerst, Fallback auf Cache/Offline-Seite
    if (url.pathname === "/m" || url.pathname.startsWith("/m/")) {
        event.respondWith(
            fetch(request).then(function (response) {
                if (response.ok && response.type === "basic") {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then(function (cache) { cache.put(request, copy); });
                }
                return response;
            }).catch(function () {
                return caches.match(request, { ignoreSearch: url.pathname === "/m" }).then(function (cached) {
                    return cached || caches.match("/m").then(function (shell) { return shell || caches.match("/offline.html"); });
                });
            })
        );
    }
});
