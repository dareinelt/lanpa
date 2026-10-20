/* QR-Scanner für /m: BarcodeDetector (Chrome/Android) mit Fallback auf jsQR (iOS Safari). */
(function () {
    "use strict";
    const root = document.getElementById("scanner");
    if (!root) { return; }
    const viewport = document.getElementById("scan-viewport");
    const video = document.getElementById("scan-video");
    const status = document.getElementById("scan-status");
    const startBtn = document.getElementById("scan-start");
    const hint = document.getElementById("scan-hint");
    const codeInput = document.getElementById("scan-code");
    const lookupUrl = root.getAttribute("data-lookup-url") || "/m/lookup";

    let stream = null;
    let running = false;
    let detector = null;
    let canvas = null;
    let ctx = null;
    let frame = 0;

    // Manuelle Eingabe offline: lokal auflösen statt zum Server zu navigieren
    const manualForm = document.getElementById("scan-form");
    if (manualForm) {
        manualForm.addEventListener("submit", function (e) {
            if (!navigator.onLine && window.Offline) {
                e.preventDefault();
                document.dispatchEvent(new CustomEvent("scan:offline-code", { detail: { code: codeInput.value.trim() } }));
            }
        });
    }

    const supported = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) && (window.isSecureContext || location.hostname === "localhost");
    if (!supported) {
        startBtn.hidden = true;
        hint.textContent = window.isSecureContext ? "Keine Kamera verfügbar – Inventarnummer eingeben." : "Kamera nur über HTTPS möglich – Inventarnummer eingeben.";
        codeInput.focus();
        return;
    }

    function setStatus(text) { if (status) { status.textContent = text; } }

    async function start() {
        if (running) { return; }
        startBtn.disabled = true;
        setStatus("Kamera wird gestartet …");
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: "environment" }, width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false
            });
        } catch (err) {
            startBtn.disabled = false;
            hint.textContent = err && err.name === "NotAllowedError"
                ? "Kamerazugriff abgelehnt – bitte in den Browsereinstellungen erlauben oder Nummer eingeben."
                : "Kamera konnte nicht gestartet werden – Nummer eingeben.";
            return;
        }
        video.srcObject = stream;
        viewport.hidden = false;
        startBtn.textContent = "Scanner stoppen";
        startBtn.disabled = false;
        startBtn.classList.replace("btn-primary", "btn-secondary");
        running = true;
        try { await video.play(); } catch (e) { /* autoplay */ }

        if ("BarcodeDetector" in window) {
            try {
                const formats = await window.BarcodeDetector.getSupportedFormats();
                if (formats.includes("qr_code")) {
                    detector = new window.BarcodeDetector({ formats: ["qr_code", "code_128", "data_matrix"] });
                }
            } catch (e) { detector = null; }
        }
        if (!detector) {
            canvas = document.createElement("canvas");
            ctx = canvas.getContext("2d", { willReadFrequently: true });
        }
        setStatus(detector ? "QR-Code in den Rahmen halten" : "QR-Code in den Rahmen halten (Software-Erkennung)");
        requestAnimationFrame(tick);
    }

    function stop() {
        running = false;
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        video.srcObject = null;
        viewport.hidden = true;
        startBtn.innerHTML = startBtn.innerHTML.replace("Scanner stoppen", "QR-Code scannen");
        startBtn.classList.replace("btn-secondary", "btn-primary");
    }

    async function tick() {
        if (!running) { return; }
        if (video.readyState >= 2) {
            frame++;
            let value = null;
            try {
                if (detector) {
                    if (frame % 3 === 0) {
                        const codes = await detector.detect(video);
                        if (codes.length) { value = codes[0].rawValue; }
                    }
                } else if (typeof window.jsQR === "function" && frame % 2 === 0) {
                    const w = Math.min(video.videoWidth, 640);
                    const h = Math.round(video.videoHeight * (w / video.videoWidth));
                    if (w > 0 && h > 0) {
                        canvas.width = w; canvas.height = h;
                        ctx.drawImage(video, 0, 0, w, h);
                        const img = ctx.getImageData(0, 0, w, h);
                        const result = window.jsQR(img.data, w, h, { inversionAttempts: "dontInvert" });
                        if (result && result.data) { value = result.data; }
                    }
                }
            } catch (e) { /* Frame überspringen */ }
            if (value) {
                found(value);
                return;
            }
        }
        requestAnimationFrame(tick);
    }

    function found(value) {
        setStatus("Erkannt: " + value);
        if (navigator.vibrate) { navigator.vibrate(60); }
        stop();
        if (!navigator.onLine && window.Offline) {
            document.dispatchEvent(new CustomEvent("scan:offline-code", { detail: { code: value.trim() } }));
            return;
        }
        window.location.href = lookupUrl + "?code=" + encodeURIComponent(value.trim());
    }

    startBtn.addEventListener("click", function () { running ? stop() : start(); });
    document.addEventListener("visibilitychange", function () { if (document.hidden && running) { stop(); } });

    // Hardware-/Bluetooth-Scanner tippen meist sehr schnell + Enter: Eingabe normalisieren
    codeInput.addEventListener("input", function () {
        const v = codeInput.value;
        if (/^https?:\/\//i.test(v)) {
            // vollständige URL vom QR-Code → Formular direkt absenden
            document.getElementById("scan-form").requestSubmit();
        }
    });
})();
