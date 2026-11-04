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
 * oder rohes Base64). Auch das wird erkannt und als Bild übernommen. Damit nie ein
 * langer Base64-String im Ticket landet, gilt zusätzlich: Bilddaten werden anhand ihres
 * Bildkopfs geprüft, Zeilenumbrüche innerhalb der Daten toleriert und alle Bilddaten –
 * auch nachträglich ins Feld gelangte (z. B. per Drag & Drop) – beim Bearbeiten und
 * spätestens beim Absenden durch eine Marke ersetzt.
 */
(function () {
    "use strict";

    const TOKEN = /\[Bild (\d+)\]/g;
    const MAX_EDGE = 1400;
    const MAX_BYTES = 900 * 1024;
    const MAX_FILES = 5;
    const RENDERABLE = ["png", "jpeg", "gif", "webp"];

    /** Zusätzliche Media-Type-Parameter zwischen Typ und `;base64,` (z. B. `;charset=utf-8`). */
    const IMAGE_PARAMS = "(?:;[a-z0-9!#$&^_.+-]+=[^,;()\\s]*)*";

    /**
     * Bilddaten im Text: Markdown-Marke, allein stehende Daten-URI oder rohes Base64 mit
     * bekanntem Bildkopf. Zusätzliche Parameter, Groß-/Kleinschreibung und Zeilenumbrüche
     * innerhalb der Base64-Daten sind erlaubt.
     */
    const IMAGE_DATA_SOURCE = "!\\[[^\\]\\r\\n]{0,150}\\]\\(\\s*data:image\\/([a-z0-9.+-]+)" + IMAGE_PARAMS + ";base64,([A-Za-z0-9+/=\\s]{16,})\\s*\\)"
        + "|(?:!\\[[^\\]\\r\\n]{0,150}\\]\\(\\s*)?data:image\\/([a-z0-9.+-]+)" + IMAGE_PARAMS + ";base64,([A-Za-z0-9+/=]{64,})"
        + "|((?:iVBORw0KGgo|\\/9j\\/|R0lGOD|UklGR)[A-Za-z0-9+/=\\s]{160,})";

    /** Grobe Vorprüfung, ob ein Text überhaupt Bilddaten enthält. */
    const IMAGE_HINT = /data:image\/|(?:iVBORw0KGgo|\/9j\/|R0lGOD|UklGR)[A-Za-z0-9+/=\s]{160,}/i;

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
            store = { counter: 0, images: new Map(), note: "" };
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

    /** Base64-Daten einer Daten-URI in Bytes umwandeln; null bei ungültigen Daten. */
    function base64Bytes(payload) {
        const clean = String(payload || "").replace(/\s+/g, "");
        if (clean === "" || /[^A-Za-z0-9+/=]/.test(clean)) {
            return null;
        }
        const padded = clean + "=".repeat((4 - (clean.length % 4)) % 4);
        let binary;
        try {
            binary = atob(padded);
        } catch (e) {
            return null;
        }
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i += 1) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes;
    }

    /** Bildtyp anhand der ersten Bytes bestimmen (unabhängig vom angegebenen Media-Type). */
    function sniffImageType(bytes) {
        if (!bytes || bytes.length < 12) {
            return "";
        }
        if (bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47) { return "png"; }
        if (bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) { return "jpeg"; }
        if (bytes[0] === 0x47 && bytes[1] === 0x49 && bytes[2] === 0x46) { return "gif"; }
        if (bytes[0] === 0x52 && bytes[1] === 0x49 && bytes[2] === 0x46 && bytes[3] === 0x46
            && bytes[8] === 0x57 && bytes[9] === 0x45 && bytes[10] === 0x42 && bytes[11] === 0x50) { return "webp"; }
        return "";
    }

    /** Daten-URI aus einem Treffer des Bildmusters – nur wenn die Daten ein echtes Bild sind. */
    function imageUriFromMatch(match) {
        const payload = match[2] || match[4] || match[5] || "";
        const type = sniffImageType(base64Bytes(payload));
        if (RENDERABLE.indexOf(type) === -1) {
            return "";
        }
        return "data:image/" + type + ";base64," + payload.replace(/\s+/g, "");
    }

    function dataUriToBlob(uri) {
        const comma = uri.indexOf(",");
        if (comma === -1) {
            return null;
        }
        const mime = uri.slice(5, comma).split(";")[0] || "image/png";
        const bytes = base64Bytes(uri.slice(comma + 1));
        return bytes ? new Blob([bytes], { type: mime }) : null;
    }

    function imageDataRegex() {
        return new RegExp(IMAGE_DATA_SOURCE, "gi");
    }

    /** Alle auswertbaren Bilddaten eines Textes einsammeln (ohne Duplikate). */
    function collectImageUris(text) {
        const found = [];
        if (!text) {
            return found;
        }
        const regex = imageDataRegex();
        let match;
        while ((match = regex.exec(text)) !== null) {
            const uri = imageUriFromMatch(match);
            if (uri !== "" && found.indexOf(uri) === -1) {
                found.push(uri);
            }
        }
        return found;
    }

    /** Inhalt der Zwischenablage zu einem Typ; leer, wenn der Browser ihn nicht liefert. */
    function clipboardData(clipboard, type) {
        if (!clipboard || typeof clipboard.getData !== "function") {
            return "";
        }
        return clipboard.getData(type) || "";
    }

    /**
     * Bilddaten, die als Text im Feld stehen, durch Marken ersetzen. Das greift auch für
     * Daten, die ohne paste-Ereignis ins Feld gelangt sind (Drag & Drop, Autofill) und
     * verhindert, dass ein langer Base64-String gespeichert wird.
     */
    function absorbStrayImages(textarea) {
        const value = textarea.value;
        if (!value || !IMAGE_HINT.test(value)) {
            return;
        }
        const store = storeFor(textarea);
        const regex = imageDataRegex();
        let output = "";
        let last = 0;
        let changed = false;
        let match;
        while ((match = regex.exec(value)) !== null) {
            const uri = imageUriFromMatch(match);
            if (uri === "") {
                continue;
            }
            const token = nextToken(textarea, store);
            store.images.set(token, uri);
            output += value.slice(last, match.index) + token;
            last = match.index + match[0].length;
            changed = true;
        }
        if (!changed) {
            return;
        }
        textarea.value = output + value.slice(last);
        store.note = "";
    }

    /** Nicht auswertbare Bilddaten aus dem Feld entfernen; true, wenn etwas entfernt wurde. */
    function purgeImageData(textarea) {
        const value = textarea.value;
        if (!value || !IMAGE_HINT.test(value)) {
            return false;
        }
        const regex = imageDataRegex();
        let output = "";
        let last = 0;
        let removed = false;
        let match;
        while ((match = regex.exec(value)) !== null) {
            output += value.slice(last, match.index);
            last = match.index + match[0].length;
            removed = true;
        }
        if (!removed) {
            return false;
        }
        textarea.value = output + value.slice(last);
        return true;
    }

    function setNote(textarea, message) {
        storeFor(textarea).note = message;
        renderPreview(textarea);
    }

    /**
     * Prüft, ob der abzusendende Text – also mit aufgelösten Bilddaten – das Feldlimit
     * (`maxlength`) überschreitet. Ohne Limit (maxLength < 0) wird nichts begrenzt.
     */
    function exceedsLimit(textarea, dataUri) {
        const limit = textarea.maxLength;
        if (typeof limit !== "number" || limit <= 0) {
            return false;
        }
        const store = storeFor(textarea);
        let length = textarea.value.length;
        store.images.forEach(function (uri, token) {
            const count = textarea.value.split(token).length - 1;
            if (count > 0) {
                length += count * (("![Bild](" + uri + ")").length - token.length);
            }
        });
        return length + ("![Bild](" + dataUri + ")").length > limit;
    }

    /** Bild als Marke einfügen; die Daten bleiben in der Map und damit unsichtbar. */
    function addImage(textarea, dataUri) {
        if (exceedsLimit(textarea, dataUri)) {
            setNote(textarea, "Das Bild wurde nicht übernommen: Der Text wäre zu lang. Bitte weniger oder kleinere Bilder einfügen.");
            return;
        }
        const store = storeFor(textarea);
        store.note = "";
        const token = nextToken(textarea, store);
        store.images.set(token, dataUri);
        insertAtCaret(textarea, token);
    }

    /** Text mit vollständigen Bilddaten (Marken ersetzt). */
    function expandedValue(textarea) {
        absorbStrayImages(textarea);
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
        if (store.note) {
            const note = document.createElement("p");
            note.className = "hd-media-note";
            note.textContent = store.note;
            target.appendChild(note);
        }
        if (tokens.length === 0) {
            target.hidden = store.note === "";
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

    function isImageFile(type) {
        return typeof type === "string" && type.indexOf("image/") === 0 && type !== "image/svg+xml";
    }

    /** Bilddateien aus der Zwischenablage (auch Browser ohne items-Unterstützung). */
    function clipboardFiles(clipboard) {
        const files = [];
        const add = function (file) {
            if (file && isImageFile(file.type) && files.indexOf(file) === -1) {
                files.push(file);
            }
        };
        if (clipboard.items) {
            for (let i = 0; i < clipboard.items.length; i += 1) {
                const item = clipboard.items[i];
                if (item.kind === "file" && isImageFile(item.type)) {
                    add(item.getAsFile());
                }
            }
        }
        if (files.length === 0 && clipboard.files) {
            for (let i = 0; i < clipboard.files.length; i += 1) {
                add(clipboard.files[i]);
            }
        }
        return files;
    }

    /** Bild verkleinern und als Marke übernehmen; scheitert das, bleibt ein Hinweis. */
    function acceptBlob(textarea, blob) {
        downscale(blob, function (dataUri) {
            if (dataUri) {
                addImage(textarea, dataUri);
                return;
            }
            setNote(textarea, "Das eingefügte Bild konnte nicht übernommen werden. Bitte als Datei anhängen.");
        });
    }

    /** Bereits geprüfte Daten-URI übernehmen (Verkleinerung, sonst unverändert speichern). */
    function acceptUri(textarea, uri) {
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
    }

    function handlePaste(event, textarea) {
        const clipboard = event.clipboardData;
        if (!clipboard) { return; }

        const files = clipboardFiles(clipboard);
        if (files.length > 0) {
            event.preventDefault();
            files.forEach(function (file) {
                acceptBlob(textarea, file);
            });
            return;
        }

        // Fallback: Screenshot liegt nur als Text (data:image/… oder rohes Base64) vor.
        const text = clipboardData(clipboard, "text/plain");
        const html = clipboardData(clipboard, "text/html");
        const images = collectImageUris(text);
        // Manche Werkzeuge liefern die Daten nur in der HTML-Variante der Zwischenablage.
        const fromHtml = images.length === 0 ? collectImageUris(html) : [];
        if (images.length === 0 && fromHtml.length === 0) {
            if (!IMAGE_HINT.test(text) && !IMAGE_HINT.test(html)) {
                return;
            }
            event.preventDefault();
            setNote(textarea, "Das Bild aus der Zwischenablage konnte nicht gelesen werden. Bitte als Datei anhängen.");
            return;
        }
        event.preventDefault();

        // Begleittext erhalten, die Bilddaten selbst aber nie als Text einfügen.
        const rest = text.replace(imageDataRegex(), "\n").replace(/[ \t]+\n/g, "\n").trim();
        if (rest !== "") {
            insertAtCaret(textarea, rest);
        }
        images.concat(fromHtml).forEach(function (uri) {
            acceptUri(textarea, uri);
        });
        absorbStrayImages(textarea);
        if (purgeImageData(textarea)) {
            setNote(textarea, "Nicht lesbare Bilddaten wurden nicht übernommen. Bitte das Bild als Datei anhängen.");
        }
    }

    function setupTextarea(textarea) {
        absorbStrayImages(textarea);
        textarea.addEventListener("paste", function (event) {
            handlePaste(event, textarea);
        });
        textarea.addEventListener("input", function () {
            absorbStrayImages(textarea);
            renderPreview(textarea);
        });
        // Drag & Drop und Autofill lösen kein paste-Ereignis aus.
        textarea.addEventListener("blur", function () {
            absorbStrayImages(textarea);
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
