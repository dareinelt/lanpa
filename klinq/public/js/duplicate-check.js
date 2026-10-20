/* Live-Dublettenhinweis beim Anlegen von Herstellern und Artikeln.
 * data-duplicate-check = API-URL; optional data-duplicate-entity (Linkziel) und
 * weitere Felder mit data-duplicate-context="<param>", deren Wert mitgesendet wird. */
(function () {
    "use strict";
    const input = document.querySelector("[data-duplicate-check]");
    const box = document.getElementById("duplicate-live");
    if (!input || !box) { return; }
    const url = input.getAttribute("data-duplicate-check");
    const entity = input.getAttribute("data-duplicate-entity") || "manufacturers";
    const labels = { manufacturers: "Ähnliche Hersteller vorhanden:", articles: "Ähnliche Artikel vorhanden:" };
    const original = input.value;
    const context = Array.prototype.slice.call(document.querySelectorAll("[data-duplicate-context]"));

    function link(d) {
        const href = entity === "articles" ? "/articles/" + d.id + "/edit" : "/" + entity + "/" + d.id;
        const prefix = d.manufacturer_name ? AppUI.escapeHtml(d.manufacturer_name) + " " : "";
        const number = d.article_number ? ' <span class="mono">(' + AppUI.escapeHtml(d.article_number) + ")</span>" : "";
        return '<a href="' + href + '">' + prefix + AppUI.escapeHtml(d.name) + "</a>" + number;
    }

    const check = AppUI.debounce(async function () {
        const name = input.value.trim();
        if (name.length < 2 || name === original) { box.hidden = true; return; }
        let query = url + (url.indexOf("?") >= 0 ? "&" : "?") + "name=" + encodeURIComponent(name);
        context.forEach(function (el) {
            if (el.value) { query += "&" + encodeURIComponent(el.getAttribute("data-duplicate-context")) + "=" + encodeURIComponent(el.value); }
        });
        try {
            const data = await AppUI.api(query);
            if (!data.duplicates.length) { box.hidden = true; return; }
            box.innerHTML = '<div class="alert alert-warning alert-stacked alert-compact mb-0">' +
                '<span class="text-sm"><strong>Hinweis:</strong> ' + (labels[entity] || labels.manufacturers) + '</span><ul class="duplicate-hint">' +
                data.duplicates.slice(0, 5).map(function (d) {
                    return '<li class="text-sm">' + link(d) + " – " + AppUI.escapeHtml(d.reason) + (d.is_active ? "" : " · inaktiv") + "</li>";
                }).join("") + "</ul></div>";
            box.hidden = false;
        } catch (e) { box.hidden = true; }
    }, 300);
    input.addEventListener("input", check);
    context.forEach(function (el) { el.addEventListener("change", check); });
})();
