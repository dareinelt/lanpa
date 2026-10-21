/**
 * Bilder aus der Zwischenablage und Anhänge im Help Desk.
 *
 * Textfelder mit data-hd-media-images nehmen per STRG+V Screenshots auf. Das Bild wird
 * im Browser verkleinert und als Base64-Daten-URI in der Markdown-Schreibweise
 * ![Bild](data:image/png;base64,…) in den Text eingefügt – dadurch bleibt es Teil des
 * Textes und wird gemeinsam mit dem Ticket bzw. Kommentar gespeichert.
 */
(function () {
    "use strict";

    const IMAGE_MARKER = /!\[([^\]\r\n]{0,150})\]\(data:image\/(png|jpe?g|gif|webp);base64,([A-Za-z0-9+/=]+)\)/g;
    const MAX_EDGE = 1400;
    const MAX_BYTES = 900 * 1024;
    const ACCEPTED = ["image/png", "image/jpeg", "image/webp", "image/gif"];
    const MAX_FILES = 5;

    function byteLength(text) {
        return new Blob([text]).size;
    }

    function formatBytes(bytes) {
        if (bytes >= 1048576) { return (bytes / 1048576).toFixed(1) + " MB"; }
        if (bytes >= 1024) { return Math.round(bytes / 1024) + " KB"; }
        return bytes + " B";
    }

    /** Zeichnet das Bild verkleinert auf ein Canvas und liefert eine JPEG-Daten-URI. */
    function downscale(blob, callback) {
        const url = URL.createObjectURL(blob);
        const img = new Image();
        img.onload = function () {
            URL.revokeObjectURL(url);
            let width = img.naturalWidth;
            let height = img.naturalHeight;
            const factor = Math.min(1, MAX_EDGE / Math.max(width, height));
            width = Math.max(1, Math.round(width * factor));
            height = Math.max(1, Math.round(height * factor));

            const canvas = document.createElement("canvas");
            canvas.width = width;
            canvas.height = height;
            const context = canvas.getContext("2d");
            if (!context) { callback(null); return; }
            context.fillStyle = "#ffffff";
            context.fillRect(0, 0, width, height);
            context.drawImage(img, 0, 0, width, height);

            let quality = 0.85;
            let data = canvas.toDataURL("image/jpeg", quality);
            while (byteLength(data) > MAX_BYTES && quality > 0.4) {
                quality -= 0.15;
                data = canvas.toDataURL("image/jpeg", quality);
            }
            callback(data);
        };
        img.onerror = function () {
            URL.revokeObjectURL(url);
            callback(null);
        };
        img.src = url;
    }

    /** Fügt Text an der Cursorposition ein und löst ein input-Ereignis aus. */
    function insertAtCaret(textarea, text) {
        const start = typeof textarea.selectionStart === "number" ? textarea.selectionStart : textarea.value.length;
        const end = typeof textarea.selectionEnd === "number" ? textarea.selectionEnd : textarea.value.length;
        const before = textarea.value.slice(0, start);
        const after = textarea.value.slice(end);
        const prefix = before === "" || before.endsWith("\n") ? "" : "\n";
        const suffix = after === "" || after.startsWith("\n") ? "\n" : "\n\n";
        textarea.value = before + prefix + text + suffix + after;
        const caret = (before + prefix + text + suffix).length;
        textarea.selectionStart = caret;
        textarea.selectionEnd = caret;
        textarea.dispatchEvent(new Event("input", { bubbles: true }));
    }

    function previewFor(textarea) {
        const selector = textarea.getAttribute("data-hd-media-preview");
        return selector ? document.querySelector(selector) : null;
    }

    function renderPreview(textarea) {
        const target = previewFor(textarea);
        if (!target) { return; }
        const images = [];
        IMAGE_MARKER.lastIndex = 0;
        let match;
        while ((match = IMAGE_MARKER.exec(textarea.value)) !== null) {
            images.push("data:image/" + match[2] + ";base64," + match[3]);
        }
        target.textContent = "";
        if (images.length === 0) {
            target.hidden = true;
            return;
        }
        images.forEach(function (src, index) {
            const figure = document.createElement("figure");
            figure.className = "hd-media-thumb";
            const img = document.createElement("img");
            img.src = src;
            img.alt = "Eingefügtes Bild " + (index + 1);
            img.loading = "lazy";
            const caption = document.createElement("figcaption");
            caption.textContent = "Bild " + (index + 1) + " · " + formatBytes(byteLength(src));
            figure.appendChild(img);
            figure.appendChild(caption);
            target.appendChild(figure);
        });
        target.hidden = false;
    }

    function handlePaste(event, textarea) {
        const clipboard = event.clipboardData;
        if (!clipboard || !clipboard.items) { return; }
        const files = [];
        for (let i = 0; i < clipboard.items.length; i++) {
            const item = clipboard.items[i];
            if (item.kind === "file" && ACCEPTED.indexOf(item.type) !== -1) {
                const file = item.getAsFile();
                if (file) { files.push(file); }
            }
        }
        if (files.length === 0) { return; }
        event.preventDefault();
        files.forEach(function (file) {
            downscale(file, function (dataUri) {
                if (!dataUri) { return; }
                insertAtCaret(textarea, "![Bild](" + dataUri + ")");
                renderPreview(textarea);
            });
        });
    }

    function setupTextarea(textarea) {
        textarea.addEventListener("paste", function (event) {
            handlePaste(event, textarea);
        });
        textarea.addEventListener("input", function () {
            renderPreview(textarea);
        });
        renderPreview(textarea);
    }

    function setupFileInput(input) {
        const selector = input.getAttribute("data-hd-file-list");
        const target = selector ? document.querySelector(selector) : null;
        if (!target) { return; }
        input.addEventListener("change", function () {
            target.textContent = "";
            const files = Array.prototype.slice.call(input.files || []);
            if (files.length === 0) {
                target.hidden = true;
                return;
            }
            files.forEach(function (file, index) {
                const item = document.createElement("li");
                if (index >= MAX_FILES) {
                    item.className = "is-rejected";
                    item.textContent = file.name + " – wird nicht übernommen (max. " + MAX_FILES + " Dateien)";
                } else {
                    item.textContent = file.name + " · " + formatBytes(file.size);
                }
                target.appendChild(item);
            });
            target.hidden = false;
        });
    }

    document.querySelectorAll("textarea[data-hd-media-images]").forEach(setupTextarea);
    document.querySelectorAll("input[type=file][data-hd-file-input]").forEach(setupFileInput);
}());
