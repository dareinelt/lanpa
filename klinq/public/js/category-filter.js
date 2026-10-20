/* Kategorie-Auswahl auf den gewählten Assettyp einschränken */
(function () {
    "use strict";
    const typeSelect = document.querySelector("[data-category-filter]");
    const categorySelect = document.getElementById("f-category");
    if (!typeSelect || !categorySelect) { return; }
    function apply() {
        const type = typeSelect.value;
        let keepSelected = false;
        Array.prototype.forEach.call(categorySelect.options, function (opt) {
            if (!opt.value) { return; }
            const visible = !type || opt.getAttribute("data-type") === type;
            opt.hidden = !visible;
            opt.disabled = !visible;
            if (opt.selected && visible) { keepSelected = true; }
        });
        if (!keepSelected) { categorySelect.value = ""; }
    }
    typeSelect.addEventListener("change", apply);
    apply();
})();
