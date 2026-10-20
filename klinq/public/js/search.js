/* Globale Suche in der Topbar */
(function () {
    "use strict";
    const input = document.getElementById("global-search-input");
    const results = document.getElementById("global-search-results");
    if (!input || !results) { return; }

    let activeIndex = -1;
    let controller = null;

    function render(groups) {
        results.innerHTML = "";
        let count = 0;
        groups.forEach(function (group) {
            if (!group.items.length) { return; }
            const label = document.createElement("li");
            label.className = "search-group";
            label.textContent = group.label;
            results.appendChild(label);
            group.items.forEach(function (item) {
                const li = document.createElement("li");
                const a = document.createElement("a");
                a.className = "search-result";
                a.href = item.url;
                a.innerHTML = '<span class="result-title">' + AppUI.escapeHtml(item.title) + '</span>' +
                    '<span class="result-meta">' + AppUI.escapeHtml(item.meta || "") + '</span>';
                li.appendChild(a);
                results.appendChild(li);
                count++;
            });
        });
        if (count === 0) {
            const li = document.createElement("li");
            li.className = "search-empty";
            li.textContent = "Keine Treffer";
            results.appendChild(li);
        }
        results.hidden = false;
        input.setAttribute("aria-expanded", "true");
        activeIndex = -1;
    }

    function hide() {
        results.hidden = true;
        input.setAttribute("aria-expanded", "false");
    }

    const search = AppUI.debounce(async function (term) {
        if (term.length < 2) { hide(); return; }
        if (controller) { controller.abort(); }
        controller = new AbortController();
        try {
            const data = await AppUI.api("/api/search?q=" + encodeURIComponent(term), { signal: controller.signal });
            if (data.redirect) { window.location.href = data.redirect; return; }
            render(data.groups || []);
        } catch (e) {
            if (e.name !== "AbortError") { hide(); }
        }
    }, 200);

    input.addEventListener("input", function () { search(input.value.trim()); });
    input.addEventListener("keydown", function (event) {
        const items = results.querySelectorAll(".search-result");
        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
            event.preventDefault();
            if (!items.length) { return; }
            activeIndex = event.key === "ArrowDown" ? Math.min(activeIndex + 1, items.length - 1) : Math.max(activeIndex - 1, 0);
            items.forEach(function (el, i) { el.classList.toggle("is-active", i === activeIndex); });
            items[activeIndex].scrollIntoView({ block: "nearest" });
        } else if (event.key === "Enter") {
            if (activeIndex >= 0 && items[activeIndex]) {
                event.preventDefault();
                window.location.href = items[activeIndex].href;
            } else if (input.value.trim()) {
                event.preventDefault();
                window.location.href = "/search?q=" + encodeURIComponent(input.value.trim());
            }
        } else if (event.key === "Escape") {
            hide();
        }
    });
    document.addEventListener("click", function (event) {
        if (!event.target.closest("#global-search")) { hide(); }
    });
})();
