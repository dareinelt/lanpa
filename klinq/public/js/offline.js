/* Offline-Kern der mobilen Erfassung: IndexedDB-Cache der Stammdaten, Warteschlange offline erfasster
   Vorgänge und idempotente Synchronisation gegen /api/offline/sync.
   Jeder Vorgang trägt eine clientseitig erzeugte Transaktions-ID; der Server legt pro ID höchstens eine
   Bewegung an – erneutes Senden ist deshalb gefahrlos. */
(function () {
    "use strict";

    const DB_NAME = "assets-offline";
    const DB_VERSION = 1;
    const BOOTSTRAP_URL = "/api/offline/bootstrap";
    const SYNC_URL = "/api/offline/sync";
    const BOOTSTRAP_MAX_AGE_MS = 10 * 60 * 1000;
    const STORES = ["meta", "assets", "employees", "locations", "cost_centers", "queue"];

    let dbPromise = null;
    let syncing = null;

    function openDb() {
        if (dbPromise) { return dbPromise; }
        dbPromise = new Promise(function (resolve, reject) {
            if (!("indexedDB" in window)) { reject(new Error("IndexedDB nicht verfügbar")); return; }
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = function () {
                const db = req.result;
                if (!db.objectStoreNames.contains("meta")) { db.createObjectStore("meta"); }
                if (!db.objectStoreNames.contains("assets")) {
                    const s = db.createObjectStore("assets", { keyPath: "id" });
                    s.createIndex("inventory", "inventory_key", { unique: false });
                    s.createIndex("serial", "serial_key", { unique: false });
                }
                ["employees", "locations", "cost_centers"].forEach(function (name) {
                    if (!db.objectStoreNames.contains(name)) { db.createObjectStore(name, { keyPath: "id" }); }
                });
                if (!db.objectStoreNames.contains("queue")) {
                    const q = db.createObjectStore("queue", { keyPath: "client_transaction_id" });
                    q.createIndex("status", "status", { unique: false });
                }
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
        return dbPromise;
    }

    function tx(storeNames, mode, fn) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                const t = db.transaction(storeNames, mode);
                let result;
                t.oncomplete = function () { resolve(result); };
                t.onerror = function () { reject(t.error); };
                t.onabort = function () { reject(t.error); };
                result = fn(t);
            });
        });
    }

    function request(req) {
        return new Promise(function (resolve, reject) {
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
    }

    function getAll(store) {
        return openDb().then(function (db) { return request(db.transaction(store, "readonly").objectStore(store).getAll()); });
    }

    function getMeta(key) {
        return openDb().then(function (db) { return request(db.transaction("meta", "readonly").objectStore("meta").get(key)); });
    }

    function setMeta(key, value) {
        return tx("meta", "readwrite", function (t) { t.objectStore("meta").put(value, key); });
    }

    function normalizeCode(value) {
        let v = String(value || "").trim();
        if (/^https?:\/\//i.test(v)) {
            // QR-Codes enthalten die Asset-URL: letztes Pfadsegment ist die Inventarnummer
            try { v = decodeURIComponent(new URL(v).pathname.split("/").filter(Boolean).pop() || ""); } catch (e) { /* ignorieren */ }
        }
        return v.replace(/[\s-]/g, "").toUpperCase();
    }

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute("content") : "";
    }

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) { return window.crypto.randomUUID(); }
        const bytes = new Uint8Array(16);
        (window.crypto || {}).getRandomValues ? window.crypto.getRandomValues(bytes) : bytes.forEach(function (_, i) { bytes[i] = Math.floor(Math.random() * 256); });
        return Array.from(bytes, function (b) { return b.toString(16).padStart(2, "0"); }).join("");
    }

    function emit(name, detail) {
        document.dispatchEvent(new CustomEvent(name, { detail: detail || {} }));
    }

    // ------------------------------------------------------------------ Stammdaten-Cache

    function storeBootstrap(data) {
        return tx(["meta", "assets", "employees", "locations", "cost_centers"], "readwrite", function (t) {
            ["assets", "employees", "locations", "cost_centers"].forEach(function (name) { t.objectStore(name).clear(); });
            const assets = t.objectStore("assets");
            (data.assets || []).forEach(function (a) {
                a.inventory_key = normalizeCode(a.inventory_number);
                a.serial_key = a.serial_number ? normalizeCode(a.serial_number) : "";
                assets.put(a);
            });
            (data.employees || []).forEach(function (e) { t.objectStore("employees").put(e); });
            (data.locations || []).forEach(function (l) { t.objectStore("locations").put(l); });
            (data.cost_centers || []).forEach(function (c) { t.objectStore("cost_centers").put(c); });
            const meta = t.objectStore("meta");
            meta.put(Date.now(), "bootstrap_at");
            meta.put(data.permissions || {}, "permissions");
            meta.put(data.user || {}, "user");
            meta.put(data.conditions || {}, "conditions");
            meta.put(data.return_targets || {}, "return_targets");
            meta.put((data.assets || []).length, "asset_count");
        });
    }

    /** Stammdaten laden, wenn online und Cache älter als 10 Minuten (oder force). */
    function refreshBootstrap(force) {
        if (!navigator.onLine) { return Promise.resolve(false); }
        return getMeta("bootstrap_at").then(function (at) {
            if (!force && at && Date.now() - at < BOOTSTRAP_MAX_AGE_MS) { return false; }
            return fetch(BOOTSTRAP_URL, { headers: { Accept: "application/json" }, credentials: "same-origin" })
                .then(function (r) { if (!r.ok) { throw new Error("bootstrap " + r.status); } return r.json(); })
                .then(function (data) { return storeBootstrap(data).then(function () { emit("offline:bootstrap", { count: (data.assets || []).length }); return true; }); })
                .catch(function () { return false; });
        }).catch(function () { return false; });
    }

    function findAsset(code) {
        const key = normalizeCode(code);
        if (!key) { return Promise.resolve(null); }
        return openDb().then(function (db) {
            const store = db.transaction("assets", "readonly").objectStore("assets");
            return request(store.index("inventory").get(key)).then(function (hit) {
                return hit || request(store.index("serial").get(key));
            });
        }).then(function (hit) { return hit || null; });
    }

    function searchList(store, term, limit) {
        const q = String(term || "").trim().toLowerCase();
        return getAll(store).then(function (rows) {
            if (!q) { return rows.slice(0, limit || 8); }
            return rows.filter(function (r) {
                return [r.name, r.meta, r.number, r.description].some(function (v) { return v && String(v).toLowerCase().indexOf(q) !== -1; });
            }).slice(0, limit || 8);
        });
    }

    // ------------------------------------------------------------------ Warteschlange

    function enqueue(item) {
        const entry = {
            client_transaction_id: item.client_transaction_id || uuid(),
            type: item.type,
            label: item.label || "",
            inventory_number: item.inventory_number || "",
            payload: item.payload || {},
            photos: item.photos || [],
            created_at: new Date().toISOString(),
            status: "pending",
            attempts: 0,
            message: "",
            conflict: null,
            force: false
        };
        entry.payload.client_transaction_id = entry.client_transaction_id;
        return tx("queue", "readwrite", function (t) { t.objectStore("queue").put(entry); })
            .then(function () { emit("offline:queue-changed"); return entry; });
    }

    function listQueue() {
        return getAll("queue").then(function (rows) {
            return rows.sort(function (a, b) { return a.created_at < b.created_at ? -1 : 1; });
        });
    }

    function removeFromQueue(id) {
        return tx("queue", "readwrite", function (t) { t.objectStore("queue").delete(id); }).then(function () { emit("offline:queue-changed"); });
    }

    function updateQueueItem(id, changes) {
        return tx("queue", "readwrite", function (t) {
            const store = t.objectStore("queue");
            const req = store.get(id);
            req.onsuccess = function () {
                if (!req.result) { return; }
                store.put(Object.assign(req.result, changes));
            };
        }).then(function () { emit("offline:queue-changed"); });
    }

    /** Erneut in die Übertragung nehmen; force=true übernimmt den Vorgang trotz Versionskonflikt (Nutzer hat bestätigt). */
    function retry(id, force) {
        return updateQueueItem(id, { status: "pending", force: !!force, message: "" }).then(function () { return sync(); });
    }

    function pendingCount() {
        return getAll("queue").then(function (rows) { return rows.length; });
    }

    /** Alle wartenden Vorgänge übertragen. Läuft nie doppelt parallel. */
    function sync() {
        if (syncing) { return syncing; }
        if (!navigator.onLine) { return Promise.resolve({ sent: 0 }); }
        syncing = listQueue().then(function (rows) {
            const pending = rows.filter(function (r) { return r.status === "pending"; });
            if (!pending.length) { return { sent: 0 }; }
            emit("offline:sync-start", { count: pending.length });
            const body = {
                transactions: pending.map(function (r) {
                    return { client_transaction_id: r.client_transaction_id, type: r.type, payload: r.payload, photos: r.photos, force: r.force };
                })
            };
            return fetch(SYNC_URL, {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-Token": csrfToken() },
                body: JSON.stringify(body)
            }).then(function (r) {
                if (r.status === 401) { throw Object.assign(new Error("Nicht angemeldet – bitte anmelden, danach werden die Vorgänge übertragen."), { code: "unauthenticated" }); }
                if (r.status === 403) { throw Object.assign(new Error("Sitzung abgelaufen – Seite neu laden und erneut senden."), { code: "csrf" }); }
                if (!r.ok) { throw new Error("Synchronisation fehlgeschlagen (" + r.status + ")."); }
                return r.json();
            }).then(function (data) {
                const results = data.results || [];
                let ok = 0;
                let failed = 0;
                return results.reduce(function (chain, res) {
                    return chain.then(function () {
                        if (res.status === "ok" || res.status === "duplicate") {
                            ok++;
                            return removeFromQueue(res.client_transaction_id);
                        }
                        failed++;
                        return updateQueueItem(res.client_transaction_id, {
                            status: res.status, message: res.message || "Fehler", conflict: res.conflict || null, errors: res.errors || null, force: false,
                            attempts: 1
                        });
                    });
                }, Promise.resolve()).then(function () {
                    const summary = { sent: pending.length, ok: ok, failed: failed, results: results };
                    // Nach erfolgreicher Übertragung sind die lokalen Assetdaten veraltet
                    if (ok > 0) { setMeta("bootstrap_at", 0).then(function () { refreshBootstrap(true); }); }
                    emit("offline:sync-done", summary);
                    return summary;
                });
            });
        }).catch(function (err) {
            emit("offline:sync-error", { message: err.message, code: err.code });
            return { sent: 0, error: err.message };
        }).then(function (r) { syncing = null; return r; });
        return syncing;
    }

    // ------------------------------------------------------------------ Anzeige: Zähler im Kopf

    function updateBadges() {
        pendingCount().then(function (n) {
            document.querySelectorAll("[data-queue-count]").forEach(function (el) {
                el.textContent = String(n);
                el.hidden = n === 0;
            });
        }).catch(function () { /* kein IndexedDB */ });
    }
    document.addEventListener("offline:queue-changed", updateBadges);
    document.addEventListener("DOMContentLoaded", updateBadges);

    window.Offline = {
        refreshBootstrap: refreshBootstrap,
        findAsset: findAsset,
        searchList: searchList,
        getAll: getAll,
        getMeta: getMeta,
        enqueue: enqueue,
        listQueue: listQueue,
        removeFromQueue: removeFromQueue,
        retry: retry,
        sync: sync,
        pendingCount: pendingCount,
        normalizeCode: normalizeCode,
        uuid: uuid
    };
})();
