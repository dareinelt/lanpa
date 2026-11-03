/**
 * Isolierte Anzeige eingehender HTML-E-Mails.
 *
 * Die Mail liegt in einem <iframe sandbox> mit eigener CSP (ohne Skripte). Dieses
 * Skript passt die Höhe des Rahmens an den Inhalt an, damit keine zweite Scrollleiste
 * entsteht. Möglich ist das, weil der Rahmen denselben Ursprung hat (allow-same-origin);
 * im Rahmen selbst läuft kein JavaScript. Ohne JavaScript bleibt die Standardhöhe aus dem CSS.
 */
(function () {
    "use strict";

    function fit(frame) {
        let doc;
        try {
            doc = frame.contentDocument;
        } catch (e) {
            return;
        }
        if (!doc || !doc.documentElement || !doc.body) { return; }
        const root = doc.documentElement;
        // body.scrollHeight hängt nicht an der Rahmenhöhe – so kann der Rahmen auch wieder schrumpfen
        let height = Math.ceil(Math.max(doc.body.scrollHeight, doc.body.getBoundingClientRect().height));
        // Breite Mails (Tabellen) bekommen eine waagrechte Scrollleiste; deren Höhe einrechnen
        if (root.scrollWidth > root.clientWidth) {
            height += frame.offsetHeight - root.clientHeight;
        }
        if (height > 0) {
            frame.style.height = height + "px";
        }
    }

    function watch(frame) {
        let observer = null;
        frame.addEventListener("load", function () {
            fit(frame);
            if (observer) { observer.disconnect(); }
            const doc = frame.contentDocument;
            if (doc && doc.documentElement && "ResizeObserver" in window) {
                observer = new ResizeObserver(function () { fit(frame); });
                observer.observe(doc.documentElement);
            }
            // Bilder laden ggf. erst nach dem load-Ereignis des Dokuments nach
            if (doc) {
                doc.querySelectorAll("img").forEach(function (img) {
                    if (!img.complete) { img.addEventListener("load", function () { fit(frame); }); }
                });
            }
        });
        fit(frame);
    }

    document.querySelectorAll("iframe[data-hd-mail-frame]").forEach(watch);
}());
