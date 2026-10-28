/* Service Worker: App-Shell-Cache für den mobilen Bereich; API-Aufrufe werden nicht gecacht. */
const CACHE_NAME = "klinq-shell-v7";
// KLINQ kann hinter dem Intranet-Proxy unter einem Unterpfad laufen (z. B. /klinq).
// Der Service Worker kennt die Wurzel dann nur über seine eigene Adresse.
const BASE = self.location.pathname.endsWith("/sw.js")
    ? self.location.pathname.slice(0, -"/sw.js".length)
    : "";
const SHELL = [
    BASE + "/m",
    BASE + "/css/app.css",
    BASE + "/js/ui.js",
    BASE + "/js/pwa.js",
    BASE + "/js/offline.js",
    BASE + "/js/offline-ui.js",
    BASE + "/js/mobile.js",
    BASE + "/js/picker.js",
    BASE + "/js/scan.js",
    BASE + "/js/search.js",
    BASE + "/js/vendor/jsqr.js",
    BASE + "/img/icon.svg",
    BASE + "/img/icon-192.png",
    BASE + "/img/icon-512.png",
    BASE + "/manifest.webmanifest",
    BASE + "/offline.html"
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
    if (url.pathname.startsWith(BASE + "/api/") || url.pathname === BASE + "/login" || url.pathname === BASE + "/logout") { return; }

    // Statische Dateien: Netz zuerst (Versions-Query ?v= bleibt wirksam), im Cache ohne Query
    // abgelegt, damit offline immer die zuletzt gesehene Version ausgeliefert wird.
    // cache: "no-cache" erzwingt die Revalidierung beim Server, damit der HTTP-Cache
    // des Browsers keine veraltete Datei an den Service Worker weiterreicht.
    if (/\.(css|js|svg|png|webmanifest|woff2?)$/.test(url.pathname)) {
        const cacheKey = url.origin + url.pathname;
        event.respondWith(
            fetch(request, { cache: "no-cache" }).then(function (response) {
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
    if (url.pathname === BASE + "/m" || url.pathname.startsWith(BASE + "/m/")) {
        event.respondWith(
            fetch(request).then(function (response) {
                if (response.ok && response.type === "basic") {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then(function (cache) { cache.put(request, copy); });
                }
                return response;
            }).catch(function () {
                return caches.match(request, { ignoreSearch: url.pathname === BASE + "/m" }).then(function (cached) {
                    return cached || caches.match(BASE + "/m").then(function (shell) { return shell || caches.match(BASE + "/offline.html"); });
                });
            })
        );
    }
});
