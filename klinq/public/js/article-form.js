/* Artikel-Formular: Verbrauchsmaterial hat keinen Assettyp (und damit keine Kategorie) */
(function () {
    "use strict";
    const toggle = document.querySelector("[data-consumable-toggle]");
    const typeSelect = document.querySelector("[data-consumable-type]");
    if (!toggle || !typeSelect) { return; }
    const categorySelect = document.querySelector("[data-consumable-category]");
    const hint = document.querySelector("[data-consumable-hint]");
    const requiredMarker = document.querySelector("[data-consumable-required]");

    function apply() {
        const consumable = toggle.checked;
        typeSelect.disabled = consumable;
        typeSelect.required = !consumable;
        if (consumable) {
            typeSelect.value = "";
            if (categorySelect) {
                categorySelect.value = "";
                categorySelect.disabled = true;
            }
        } else if (categorySelect) {
            categorySelect.disabled = false;
        }
        if (hint) { hint.hidden = !consumable; }
        if (requiredMarker) { requiredMarker.hidden = consumable; }
        // Kategorieoptionen an den (ggf. geleerten) Assettyp anpassen
        typeSelect.dispatchEvent(new Event("change"));
    }

    toggle.addEventListener("change", apply);
    apply();
})();
