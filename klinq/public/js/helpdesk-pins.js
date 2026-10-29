/**
 * Pinnadel an Kommunikations- und Aufgabeneinträgen.
 *
 * Fängt das Absenden der Anheft-Formulare ab, schickt sie per fetch an den
 * Server und sortiert die Liste anschließend neu – ohne die Seite neu zu laden.
 * Ohne JavaScript greift weiterhin der klassische Formular-POST mit Redirect.
 */
(function () {
    "use strict";

    const UI = window.AppUI || {};
    const toast = UI.toast || function () {};
    const api = UI.api;

    if (!api) { return; }

    const LABEL_PIN = "Oben anheften";
    const LABEL_UNPIN = "Anheftung lösen";

    function messageOf(error) {
        const data = error && error.data;
        if (data && data.errors) {
            const messages = Object.keys(data.errors)
                .map(function (key) { return data.errors[key]; })
                .filter(function (value) { return !!value; });
            if (messages.length > 0) { return messages.join(" "); }
        }
        return (error && error.message) || "Aktion fehlgeschlagen.";
    }

    /** Sortierschlüssel analog zur Serversortierung: angeheftet zuerst, dann chronologisch. */
    function sortKey(item) {
        const pinned = item.classList.contains("is-pinned") ? "0" : "1";
        const created = item.getAttribute("data-hd-created") || "";
        const id = (item.getAttribute("data-hd-id") || "0").padStart(10, "0");

        return pinned + "|" + created + "|" + id;
    }

    function reorder(list) {
        const items = Array.prototype.slice.call(list.querySelectorAll("[data-hd-pin-item]"));
        items.sort(function (a, b) {
            const left = sortKey(a);
            const right = sortKey(b);

            return left < right ? -1 : (left > right ? 1 : 0);
        });
        items.forEach(function (item) { list.appendChild(item); });
    }

    function setBadge(item, pinned) {
        const badge = item.querySelector("[data-hd-pin-badge]");
        if (!pinned) {
            if (badge) { badge.remove(); }
            return;
        }
        if (badge) { return; }
        const head = item.querySelector(".comment-head");
        if (!head) { return; }

        const created = document.createElement("span");
        created.className = "badge badge-info";
        created.setAttribute("data-hd-pin-badge", "");
        const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
        svg.setAttribute("class", "icon icon-xs");
        svg.setAttribute("aria-hidden", "true");
        const use = document.createElementNS("http://www.w3.org/2000/svg", "use");
        use.setAttribute("href", "#icon-pin");
        svg.appendChild(use);
        created.appendChild(svg);
        created.appendChild(document.createTextNode(" Angeheftet"));

        const author = head.querySelector(".comment-author");
        if (author) {
            head.insertBefore(created, author.nextSibling);
        } else {
            head.appendChild(created);
        }
    }

    function setButtonState(form, pinned) {
        const field = form.querySelector("input[name='pinned']");
        if (field) { field.value = pinned ? "0" : "1"; }

        const button = form.querySelector("button[type='submit']");
        if (!button) { return null; }
        const label = pinned ? LABEL_UNPIN : LABEL_PIN;
        button.classList.toggle("is-active", pinned);
        button.setAttribute("aria-pressed", pinned ? "true" : "false");
        button.setAttribute("title", label);
        button.setAttribute("aria-label", label);

        return button;
    }

    document.querySelectorAll("[data-hd-pin-form]").forEach(function (form) {
        form.addEventListener("submit", function (event) {
            const item = form.closest("[data-hd-pin-item]");
            const list = item ? item.closest("[data-hd-pin-list]") : null;
            const field = form.querySelector("input[name='pinned']");
            if (!item || !list || !field) { return; }

            event.preventDefault();
            const pinned = field.value === "1";
            const button = form.querySelector("button[type='submit']");
            if (button) { button.disabled = true; }
            form.classList.add("is-busy");

            api(form.getAttribute("action"), {
                method: "POST",
                body: { pinned: pinned ? "1" : "0" }
            })
                .then(function (data) {
                    item.classList.toggle("is-pinned", pinned);
                    setButtonState(form, pinned);
                    setBadge(item, pinned);
                    reorder(list);
                    toast((data && data.message) || (pinned ? "Kommentar angeheftet." : "Anheftung gelöst."), "success");
                    if (button) {
                        button.focus({ preventScroll: true });
                        item.scrollIntoView({ block: "nearest" });
                    }
                })
                .catch(function (error) {
                    toast(messageOf(error), "danger");
                })
                .finally(function () {
                    if (button) { button.disabled = false; }
                    form.classList.remove("is-busy");
                });
        });
    });
})();
