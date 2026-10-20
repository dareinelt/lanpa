/* Unterschriftsfeld für das Übergabeprotokoll (Finger, Apple Pencil, Maus) – nach dem Vorbild von PatSign. */
(function () {
    "use strict";

    var form = document.getElementById("handover-sign-form");
    var canvas = document.getElementById("signature-pad");
    if (!form || !canvas) {
        return;
    }
    var wrapper = canvas.closest(".signature-pad-wrapper");
    var hiddenField = document.getElementById("signature-data");
    var submitButton = document.getElementById("signature-submit");
    var errorBox = document.getElementById("signature-error");
    var ctx = null;
    var hasSignature = false;
    var drawing = false;
    var lastPoint = null;

    function resizeCanvas() {
        var ratio = window.devicePixelRatio || 1;
        var rect = canvas.getBoundingClientRect();
        if (rect.width === 0) {
            return;
        }
        var image = hasSignature ? canvas.toDataURL() : null;
        canvas.width = Math.round(rect.width * ratio);
        canvas.height = Math.round(rect.height * ratio);
        ctx = canvas.getContext("2d");
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2.5;
        ctx.lineCap = "round";
        ctx.lineJoin = "round";
        ctx.strokeStyle = "#1a2733";
        if (image) {
            var img = new Image();
            img.onload = function () { ctx.drawImage(img, 0, 0, rect.width, rect.height); };
            img.src = image;
        }
    }

    function pointerPosition(event) {
        var rect = canvas.getBoundingClientRect();
        return { x: event.clientX - rect.left, y: event.clientY - rect.top };
    }

    function setSigned(value) {
        hasSignature = value;
        if (wrapper) {
            wrapper.classList.toggle("has-signature", value);
        }
    }

    function clear() {
        if (ctx) {
            ctx.save();
            ctx.setTransform(1, 0, 0, 1, 0, 0);
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.restore();
        }
        setSigned(false);
        hideError();
    }

    function showError(message) {
        if (!errorBox) {
            window.alert(message);
            return;
        }
        var span = errorBox.querySelector("span");
        if (span) { span.textContent = message; } else { errorBox.textContent = message; }
        errorBox.classList.remove("hidden");
        errorBox.scrollIntoView({ behavior: "smooth", block: "center" });
    }

    function hideError() {
        if (errorBox) {
            errorBox.classList.add("hidden");
        }
    }

    canvas.addEventListener("pointerdown", function (event) {
        event.preventDefault();
        if (!ctx) {
            resizeCanvas();
            if (!ctx) { return; }
        }
        drawing = true;
        canvas.setPointerCapture(event.pointerId);
        lastPoint = pointerPosition(event);
        ctx.beginPath();
        ctx.moveTo(lastPoint.x, lastPoint.y);
        // Punkt setzen, damit auch ein Tippen sichtbar ist
        ctx.lineTo(lastPoint.x + 0.1, lastPoint.y + 0.1);
        ctx.stroke();
    });
    canvas.addEventListener("pointermove", function (event) {
        if (!drawing) { return; }
        var pos = pointerPosition(event);
        ctx.lineTo(pos.x, pos.y);
        ctx.stroke();
        lastPoint = pos;
        if (!hasSignature) {
            setSigned(true);
            hideError();
        }
    });
    ["pointerup", "pointercancel", "pointerleave"].forEach(function (type) {
        canvas.addEventListener(type, function () { drawing = false; });
    });
    // Scrollen der Seite beim Unterschreiben verhindern (iOS)
    canvas.addEventListener("touchstart", function (e) { e.preventDefault(); }, { passive: false });
    canvas.addEventListener("touchmove", function (e) { e.preventDefault(); }, { passive: false });

    form.querySelectorAll("[data-signature-clear]").forEach(function (button) {
        button.addEventListener("click", clear);
    });

    window.addEventListener("resize", function () { window.setTimeout(resizeCanvas, 50); });
    window.addEventListener("orientationchange", function () { window.setTimeout(resizeCanvas, 150); });
    resizeCanvas();

    form.addEventListener("submit", function (event) {
        var missing = Array.prototype.filter.call(form.querySelectorAll("[data-required-confirmation]"), function (box) {
            return !box.checked;
        });
        if (missing.length > 0) {
            event.preventDefault();
            showError("Bitte alle Pflichtbestätigungen ankreuzen.");
            missing[0].closest("label").scrollIntoView({ behavior: "smooth", block: "center" });
            return;
        }
        if (!hasSignature) {
            event.preventDefault();
            showError("Bitte unterschreiben Sie im Unterschriftsfeld.");
            canvas.scrollIntoView({ behavior: "smooth", block: "center" });
            return;
        }
        hiddenField.value = canvas.toDataURL("image/png");
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = "Wird gespeichert …";
        }
    });
})();
