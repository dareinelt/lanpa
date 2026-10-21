/**
 * Bilder aus der Zwischenablage und Anhänge im Help Desk.
 *
 * Textfelder mit data-hd-media-images nehmen per STRG+V Screenshots auf. Das Bild wird
 * im Browser verkleinert und als Base64-Daten-URI gespeichert. Im Textfeld steht nur
 * eine kurze Marke wie [Bild 1]; das Bild selbst erscheint als Vorschau direkt unter
 * dem Feld. Beim Absenden ersetzt das Skript die Marke wieder durch die
 * Markdown-Schreibweise ![Bild](data:image/…), damit die Daten wie bisher gemeinsam
 * mit dem Ticket bzw. Kommentar gespeichert werden.
 *
 * Manche Werkzeuge legen Screenshots nur als Text in die Zwischenablage (data:image/…
 * oder rohes Base64). Auch das wird erkannt und als Bild übernommen.
 */
(function () {
    "use strict";

    const IMAGE_MARKER = /!\[([^\]\r\n]{0,150})\]\(data:image\/(png|jpe?g|gif|webp);base64,([A-Za-z0-9+/=]+)\)/g;
    const DATA_URI = /data:image\/(png|jpe?g|gif|webp);base64,([A-Za-z0-9+/=]+)/gi;
    const RAW_BASE64 = /((?:iVBORw0KGgo|\/9j\/|R0lGOD|UklGR)[A-Za-z0-9+/=]{80,})/g;
    const TOKEN = /\[Bild (\d+)\]/g;
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

    /** Je Textfeld: fortlaufende Nummer und die Bilddaten zu den Marken. */
    const stores = new WeakMap();

    function storeFor(textarea) {
        let store = stores.get(textarea);
        if (!store) {
            store = { counter: 0, images: new Map() };
            stores.set(textarea, store);
        }
        return store;
    }

    /** Nächste freie Marke, ohne vorhandenen Text oder belegte Marken zu überschreiben. */
    function nextToken(textarea, store) {
        let number = store.counter + 1;
        let token = "[Bild " + number + "]";
        while (store.images.has(token) || textarea.value.indexOf(token) !== -1) {
            number += 1;
            token = "[Bild " + number + "]";
        }
        store.counter = number;
        return token;
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

    function dataUriToBlob(uri) {
        const comma = uri.indexOf(",");
        const mime = uri.slice(5, comma).split(";")[0] || "image/png";
        const binary = atob(uri.slice(comma + 1));
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i += 1) {
            bytes[i] = binary.charCodeAt(i);
        }
        return new Blob([bytes], { type: mime });
    }

    function dataUriType(uri) {
        const match = /^data:image\/(png|jpe?g|gif|webp);base64,/i.exec(uri);
        return match ? match[1].toLowerCase() : "";
    }

    /** Base64-Text ohne data:-Präfix (z. B. aus einem Screenshot-Werkzeug) erkennen. */
    function rawImageDataUri(raw) {
        if (raw.indexOf("iVBORw0KGgo") === 0) { return "data:image/png;base64," + raw; }
        if (raw.indexOf("/9j/") === 0) { return "data:image/jpeg;base64," + raw; }
        if (raw.indexOf("R0lGOD") === 0) { return "data:image/gif;base64," + raw; }
        if (raw.indexOf("UklGR") === 0) { return "data:image/webp;base64," + raw; }
        return "";
    }

    /** Bilder aus Text/HTML der Zwischenablage einsammeln (ohne Duplikate). */
    function clipboardImages(clipboard) {
        const found = [];
        const add = function (uri) {
            if (uri && dataUriType(uri) && found.indexOf(uri) === -1) {
                found.push(uri);
            }
        };
        if (typeof clipboard.getData !== "function") {
            return found;
        }
        ["text/plain", "text/html"].forEach(function (type) {
            const raw = clipboard.getData(type) || "";
            DATA_URI.lastIndex = 0;
            let match;
            while ((match = DATA_URI.exec(raw)) !== null) {
                add("data:image/" + match[1].toLowerCase() + ";base64," + match[2]);
            }
            if (type === "text/plain") {
                RAW_BASE64.lastIndex = 0;
                while ((match = RAW_BASE64.exec(raw)) !== null) {
                    add(rawImageDataUri(match[1]));
                }
            }
        });
        return found;
    }

    /** Bild als Marke einfügen; die Daten bleiben in der Map und damit unsichtbar. */
    function addImage(textarea, dataUri) {
        const store = storeFor(textarea);
        const token = nextToken(textarea, store);
        store.images.set(token, dataUri);
        insertAtCaret(textarea, token);
    }

    /** Bereits gespeicherte Markdown-Bilder beim Laden durch Marken ersetzen. */
    function collapseImages(textarea) {
        const store = storeFor(textarea);
        IMAGE_MARKER.lastIndex = 0;
        const hasImages = IMAGE_MARKER.test(textarea.value);
        IMAGE_MARKER.lastIndex = 0;
        if (!hasImages) {
            return;
        }
        textarea.value = textarea.value.replace(IMAGE_MARKER, function (match, alt, type, data) {
            const token = nextToken(textarea, store);
            store.images.set(token, "data:image/" + type + ";base64," + data);
            return token;
        });
        IMAGE_MARKER.lastIndex = 0;
    }

    /** Text mit vollständigen Bilddaten (Marken ersetzt). */
    function expandedValue(textarea) {
        const store = stores.get(textarea);
        let value = textarea.value;
        if (!store) {
            return value;
        }
        store.images.forEach(function (dataUri, token) {
            if (value.indexOf(token) !== -1) {
                value = value.split(token).join("![Bild](" + dataUri + ")");
            }
        });
        return value;
    }

    function expandTextarea(textarea) {
        const value = expandedValue(textarea);
        if (value !== textarea.value) {
            textarea.value = value;
        }
    }

    function removeToken(textarea, token) {
        const store = storeFor(textarea);
        store.images.delete(token);
        textarea.value = textarea.value.split(token).join("");
        textarea.dispatchEvent(new Event("input", { bubbles: true }));
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
        const store = storeFor(textarea);
        const tokens = [];
        const seen = {};
        TOKEN.lastIndex = 0;
        let match;
        while ((match = TOKEN.exec(textarea.value)) !== null) {
            const token = match[0];
            if (!seen[token] && store.images.has(token)) {
                seen[token] = true;
                tokens.push(token);
            }
        }
        // Marken, die der Benutzer aus dem Text entfernt hat, geben ihre Daten frei.
        Array.from(store.images.keys()).forEach(function (token) {
            if (textarea.value.indexOf(token) === -1) {
                store.images.delete(token);
            }
        });
        target.textContent = "";
        if (tokens.length === 0) {
            target.hidden = true;
            return;
        }
        tokens.forEach(function (token, index) {
            const src = store.images.get(token);
            const figure = document.createElement("figure");
            figure.className = "hd-media-thumb";
            const img = document.createElement("img");
            img.src = src;
            img.alt = "Eingefügtes Bild " + (index + 1);
            img.loading = "lazy";
            const caption = document.createElement("figcaption");
            const label = document.createElement("span");
            label.textContent = "Bild " + (index + 1) + " · " + formatBytes(byteLength(src));
            const remove = document.createElement("button");
            remove.type = "button";
            remove.className = "hd-media-remove";
            remove.textContent = "Entfernen";
            remove.setAttribute("aria-label", "Bild " + (index + 1) + " entfernen");
            remove.addEventListener("click", function () {
                removeToken(textarea, token);
            });
            caption.appendChild(label);
            caption.appendChild(remove);
            figure.appendChild(img);
            figure.appendChild(caption);
            target.appendChild(figure);
        });
        target.hidden = false;
    }

    function handlePaste(event, textarea) {
        const clipboard = event.clipboardData;
        if (!clipboard) { return; }

        const files = [];
        if (clipboard.items) {
            for (let i = 0; i < clipboard.items.length; i++) {
                const item = clipboard.items[i];
                if (item.kind === "file" && ACCEPTED.indexOf(item.type) !== -1) {
                    const file = item.getAsFile();
                    if (file) { files.push(file); }
                }
            }
        }

        if (files.length > 0) {
            event.preventDefault();
            files.forEach(function (file) {
                downscale(file, function (dataUri) {
                    if (dataUri) { addImage(textarea, dataUri); }
                });
            });
            return;
        }

        // Fallback: Screenshot liegt nur als Text (data:image/… oder rohes Base64) vor.
        const images = clipboardImages(clipboard);
        if (images.length === 0) { return; }
        event.preventDefault();
        images.forEach(function (uri) {
            let blob = null;
            try {
                blob = dataUriToBlob(uri);
            } catch (e) {
                blob = null;
            }
            if (!blob) {
                addImage(textarea, uri);
                return;
            }
            downscale(blob, function (dataUri) {
                addImage(textarea, dataUri || uri);
            });
        });
    }

    function setupTextarea(textarea) {
        collapseImages(textarea);
        textarea.addEventListener("paste", function (event) {
            handlePaste(event, textarea);
        });
        textarea.addEventListener("input", function () {
            renderPreview(textarea);
        });
        const form = textarea.form;
        if (form) {
            // FormData (auch im Assistenten) erhält die vollständigen Bilddaten, ohne
            // dass der Base64-Text im Feld sichtbar wird.
            form.addEventListener("formdata", function (event) {
                if (textarea.name) {
                    event.formData.set(textarea.name, expandedValue(textarea));
                }
            });
            // Klassische Formulare ohne formdata-Ereignis: Feld kurz vor dem Absenden
            // auflösen. Der Assistent erledigt das über sein eigenes FormData.
            let collapsed = null;
            form.addEventListener("submit", function (event) {
                if (form.classList.contains("hd-wizard-form") || event.defaultPrevented) { return; }
                collapsed = textarea.value;
                expandTextarea(textarea);
            });
            // Bricht ein späterer Handler den Versand ab, bleiben die Marken stehen.
            window.addEventListener("submit", function (event) {
                if (event.target !== form || collapsed === null) { return; }
                if (event.defaultPrevented) {
                    textarea.value = collapsed;
                    renderPreview(textarea);
                }
                collapsed = null;
            });
        }
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
