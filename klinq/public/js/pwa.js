/* Service-Worker-Registrierung und Online/Offline-Anzeige */
(function () {
    "use strict";
    if ("serviceWorker" in navigator) {
        window.addEventListener("load", function () {
            navigator.serviceWorker.register("/sw.js").catch(function () { /* SW optional */ });
        });
    }
    const indicator = document.getElementById("offline-indicator");
    function update() {
        if (indicator) { indicator.hidden = navigator.onLine; }
        document.body.classList.toggle("is-offline", !navigator.onLine);
        if (navigator.onLine && window.Offline) { window.Offline.sync(); }
    }
    window.addEventListener("online", update);
    window.addEventListener("offline", update);
    update();
})();
