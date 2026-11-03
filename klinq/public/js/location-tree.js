/**
 * Standortbaum (/locations): Ein-/Ausklappen, Suche und Verschieben per Drag & Drop.
 *
 * - Eingeklappte Knoten werden im localStorage gemerkt.
 * - Die Suche filtert nach Name/Kürzel, blendet passende Einträge samt übergeordneten
 *   Standorten ein und hebt den Treffer hervor (eingeklappte Äste werden dabei geöffnet).
 * - Drag & Drop am Griff: oberes/unteres Viertel eines Eintrags = davor/danach einsortieren,
 *   Mitte = als Unterstandort einordnen. Gespeichert wird per POST /locations/{id}/move.
 */
(function () {
    "use strict";

    const tree = document.querySelector("[data-location-tree]");
    if (!tree) { return; }

    const UI = window.AppUI || {};
    const toast = UI.toast || function () {};
    const STORAGE_KEY = "klinq.locations.collapsed";
    const searchInput = document.querySelector("[data-tree-search-input]");
    const emptyHint = document.querySelector("[data-tree-empty]");

    function items() {
        return Array.prototype.slice.call(tree.querySelectorAll(".tree-item"));
    }

    function childList(item) {
        const last = item.lastElementChild;
        return last && last.tagName === "UL" ? last : null;
    }

    function parentItem(item) {
        const list = item.parentElement;
        return list ? list.closest(".tree-item") : null;
    }

    /* ---------- Ein-/Ausklappen ---------- */

    function loadCollapsed() {
        try {
            const raw = window.localStorage.getItem(STORAGE_KEY);
            const ids = raw ? JSON.parse(raw) : [];
            return Array.isArray(ids) ? ids.map(String) : [];
        } catch (e) {
            return [];
        }
    }

    function saveCollapsed() {
        const ids = items()
            .filter(function (item) { return item.classList.contains("is-collapsed"); })
            .map(function (item) { return item.getAttribute("data-tree-id"); });
        try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify(ids)); } catch (e) { /* Speicher nicht verfügbar */ }
    }

    function setCollapsed(item, collapsed) {
        const hasChildren = childList(item) !== null;
        item.classList.toggle("is-collapsed", collapsed && hasChildren);
        const toggle = item.querySelector(":scope > .tree-node [data-tree-toggle]");
        if (!toggle) { return; }
        toggle.classList.toggle("is-leaf", !hasChildren);
        toggle.disabled = !hasChildren;
        const isCollapsed = collapsed && hasChildren;
        const name = (item.querySelector(":scope > .tree-node [data-tree-name]") || {}).textContent || "";
        toggle.setAttribute("aria-expanded", isCollapsed ? "false" : "true");
        toggle.setAttribute("title", isCollapsed ? "Unterstandorte aufklappen" : "Unterstandorte einklappen");
        toggle.setAttribute("aria-label", "Unterstandorte von " + name + (isCollapsed ? " aufklappen" : " einklappen"));
    }

    /** Leere Unterlisten entfernen und den Umschalter an den Inhalt anpassen. */
    function refreshItem(item) {
        if (!item) { return; }
        const list = childList(item);
        if (list && list.children.length === 0) { list.remove(); }
        setCollapsed(item, item.classList.contains("is-collapsed"));
    }

    const collapsedIds = loadCollapsed();
    items().forEach(function (item) {
        setCollapsed(item, collapsedIds.indexOf(item.getAttribute("data-tree-id")) !== -1);
    });

    tree.addEventListener("click", function (event) {
        const toggle = event.target.closest("[data-tree-toggle]");
        if (!toggle || !tree.contains(toggle)) { return; }
        const item = toggle.closest(".tree-item");
        setCollapsed(item, !item.classList.contains("is-collapsed"));
        saveCollapsed();
    });

    function setAll(collapsed) {
        items().forEach(function (item) { setCollapsed(item, collapsed); });
        saveCollapsed();
    }

    const expandAll = document.querySelector("[data-tree-expand-all]");
    const collapseAll = document.querySelector("[data-tree-collapse-all]");
    if (expandAll) { expandAll.addEventListener("click", function () { setAll(false); }); }
    if (collapseAll) { collapseAll.addEventListener("click", function () { setAll(true); }); }

    /* ---------- Suche ---------- */

    function highlight(element, term) {
        if (!element) { return; }
        const text = element.getAttribute("data-tree-text") !== null ? element.getAttribute("data-tree-text") : element.textContent;
        element.setAttribute("data-tree-text", text);
        element.textContent = "";
        const index = term === "" ? -1 : text.toLowerCase().indexOf(term);
        if (index === -1) {
            element.textContent = text;
            return;
        }
        const mark = document.createElement("mark");
        mark.textContent = text.slice(index, index + term.length);
        element.appendChild(document.createTextNode(text.slice(0, index)));
        element.appendChild(mark);
        element.appendChild(document.createTextNode(text.slice(index + term.length)));
    }

    function applySearch() {
        const term = searchInput ? searchInput.value.trim().toLowerCase() : "";
        const searching = term !== "";
        tree.classList.toggle("is-searching", searching);
        let matches = 0;

        items().forEach(function (item) {
            item.classList.remove("is-match", "is-search-visible");
        });
        items().forEach(function (item) {
            const node = item.querySelector(":scope > .tree-node");
            highlight(node.querySelector("[data-tree-name]"), term);
            highlight(node.querySelector("[data-tree-code]"), term);
            if (!searching) { return; }
            if ((item.getAttribute("data-tree-search") || "").indexOf(term) === -1) { return; }
            matches++;
            item.classList.add("is-match", "is-search-visible");
            let ancestor = parentItem(item);
            while (ancestor) {
                ancestor.classList.add("is-search-visible");
                ancestor = parentItem(ancestor);
            }
        });

        if (emptyHint) { emptyHint.hidden = !searching || matches > 0; }
    }

    if (searchInput) {
        const run = UI.debounce ? UI.debounce(applySearch, 150) : applySearch;
        searchInput.addEventListener("input", run);
        searchInput.addEventListener("keydown", function (event) {
            if (event.key === "Escape" && searchInput.value !== "") {
                searchInput.value = "";
                applySearch();
            }
        });
        if (searchInput.value !== "") { applySearch(); }
    }

    /* ---------- Drag & Drop ---------- */

    if (!tree.hasAttribute("data-tree-movable") || !UI.api) { return; }

    let dragged = null;
    let target = null;
    let position = null;
    let busy = false;

    function clearIndicator() {
        tree.querySelectorAll(".drop-before, .drop-after, .drop-inside").forEach(function (node) {
            node.classList.remove("drop-before", "drop-after", "drop-inside");
        });
    }

    tree.addEventListener("dragstart", function (event) {
        const handle = event.target.closest ? event.target.closest("[data-tree-handle]") : null;
        if (!handle || busy || tree.classList.contains("is-searching")) {
            event.preventDefault();
            return;
        }
        dragged = handle.closest(".tree-item");
        const row = dragged.querySelector(":scope > .tree-node");
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData("text/plain", dragged.getAttribute("data-tree-id"));
        if (event.dataTransfer.setDragImage) { event.dataTransfer.setDragImage(row, 16, 16); }
        window.setTimeout(function () {
            if (dragged) {
                dragged.classList.add("is-dragging");
                tree.classList.add("is-dragging-active");
            }
        }, 0);
    });

    tree.addEventListener("dragover", function (event) {
        if (!dragged) { return; }
        const row = event.target.closest ? event.target.closest(".tree-node") : null;
        const item = row ? row.closest(".tree-item") : null;
        if (!item || dragged.contains(item)) {
            clearIndicator();
            target = null;
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = "move";
        const rect = row.getBoundingClientRect();
        const offset = (event.clientY - rect.top) / rect.height;
        const next = offset < 0.25 ? "before" : (offset > 0.75 ? "after" : "inside");
        if (target === item && position === next) { return; }
        clearIndicator();
        target = item;
        position = next;
        row.classList.add("drop-" + next);
    });

    tree.addEventListener("dragleave", function (event) {
        if (!tree.contains(event.relatedTarget)) {
            clearIndicator();
            target = null;
        }
    });

    function endDrag() {
        clearIndicator();
        if (dragged) { dragged.classList.remove("is-dragging"); }
        tree.classList.remove("is-dragging-active");
        dragged = null;
        target = null;
        position = null;
    }

    tree.addEventListener("dragend", endDrag);

    tree.addEventListener("drop", function (event) {
        if (!dragged || !target) { endDrag(); return; }
        event.preventDefault();
        const item = dragged;
        const dropTarget = target;
        const dropPosition = position;
        endDrag();
        moveItem(item, dropTarget, dropPosition);
    });

    function moveItem(item, dropTarget, dropPosition) {
        const oldList = item.parentElement;
        const oldNext = item.nextElementSibling;
        const oldParent = parentItem(item);

        if (dropPosition === "inside") {
            let list = childList(dropTarget);
            if (!list) {
                list = document.createElement("ul");
                dropTarget.appendChild(list);
            }
            list.appendChild(item);
            setCollapsed(dropTarget, false);
        } else {
            dropTarget.parentElement.insertBefore(item, dropPosition === "before" ? dropTarget : dropTarget.nextElementSibling);
        }

        const newList = item.parentElement;
        if (newList === oldList && item.nextElementSibling === oldNext) { return; }

        const newParent = parentItem(item);
        const order = Array.prototype.slice.call(newList.children).map(function (li) {
            return parseInt(li.getAttribute("data-tree-id"), 10);
        });
        refreshItem(oldParent);
        refreshItem(newParent);

        busy = true;
        tree.classList.add("is-busy");
        UI.api("/locations/" + item.getAttribute("data-tree-id") + "/move", {
            method: "POST",
            body: { parent_id: newParent ? parseInt(newParent.getAttribute("data-tree-id"), 10) : null, order: order }
        })
            .then(function (data) {
                toast((data && data.message) || "Standort wurde verschoben.", "success");
                saveCollapsed();
            })
            .catch(function (error) {
                // Ursprüngliche Position wiederherstellen
                if (oldParent && !oldList.isConnected) { oldParent.appendChild(oldList); }
                oldList.insertBefore(item, oldNext && oldNext.parentElement === oldList ? oldNext : null);
                refreshItem(newParent);
                refreshItem(oldParent);
                toast((error && error.message) || "Verschieben fehlgeschlagen.", "danger");
            })
            .finally(function () {
                busy = false;
                tree.classList.remove("is-busy");
            });
    }
})();
