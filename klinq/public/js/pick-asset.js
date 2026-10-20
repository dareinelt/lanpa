/* Asset-Auswahl vor Entnahme/Rückgabe: Autocomplete über /api/assets/search, Enter/Klick übernimmt die Inventarnummer. */
(function () {
    "use strict";
    const form = document.getElementById("pick-asset-form");
    const input = document.getElementById("f-pick-asset");
    const results = document.getElementById("pick-asset-results");
    if (!form || !input || !results) { return; }

    let activeIndex = -1;

    function render(items) {
        results.innerHTML = "";
        items.forEach(function (item) {
            const li = document.createElement("li");
            const a = document.createElement("a");
            a.className = "search-result";
            a.href = "#";
            a.innerHTML = '<span class="result-title">' + AppUI.escapeHtml(item.label || item.inventory_number) + '</span>' +
                '<span class="result-meta">' + AppUI.escapeHtml(item.meta || "") + '</span>';
            a.addEventListener("click", function (event) {
                event.preventDefault();
                input.value = item.inventory_number;
                results.hidden = true;
                form.requestSubmit ? form.requestSubmit() : form.submit();
            });
            li.appendChild(a);
            results.appendChild(li);
        });
        results.hidden = items.length === 0;
        activeIndex = -1;
    }

    const search = AppUI.debounce(async function (term) {
        if (term.length < 2) { results.hidden = true; return; }
        try {
            const data = await AppUI.api("/api/assets/search?q=" + encodeURIComponent(term));
            render(data.items || []);
        } catch (e) { results.hidden = true; }
    }, 200);

    input.addEventListener("input", function () { search(input.value.trim()); });
    input.addEventListener("keydown", function (event) {
        const items = results.querySelectorAll(".search-result");
        if (event.key === "ArrowDown" || event.key === "ArrowUp") {
            if (!items.length) { return; }
            event.preventDefault();
            activeIndex = event.key === "ArrowDown" ? Math.min(activeIndex + 1, items.length - 1) : Math.max(activeIndex - 1, 0);
            items.forEach(function (el, i) { el.classList.toggle("is-active", i === activeIndex); });
            items[activeIndex].scrollIntoView({ block: "nearest" });
        } else if (event.key === "Enter" && activeIndex >= 0 && items[activeIndex]) {
            event.preventDefault();
            items[activeIndex].click();
        } else if (event.key === "Escape") {
            results.hidden = true;
        }
    });
    document.addEventListener("click", function (event) {
        if (!event.target.closest("#pick-asset-form")) { results.hidden = true; }
    });
})();
