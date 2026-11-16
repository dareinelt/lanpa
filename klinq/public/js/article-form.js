/* Artikel-Formular: Verbrauchsmaterial hat keinen Assettyp (und damit keine Kategorie),
 * keine Übergabeprotokoll-Relevanz und blendet die Bestandsfelder kontextabhängig ein. */
(function () {
    "use strict";
    const toggle = document.querySelector("[data-consumable-toggle]");
    const typeSelect = document.querySelector("[data-consumable-type]");
    if (!toggle || !typeSelect) { return; }
    const categorySelect = document.querySelector("[data-consumable-category]");
    const handoverCheckbox = document.querySelector("[data-consumable-handover]");
    const stockRow = document.querySelector("[data-consumable-stock]");
    const nameInput = document.querySelector("[data-consumable-name]");
    const hint = document.querySelector("[data-consumable-hint]");
    const requiredMarker = document.querySelector("[data-consumable-required]");
    const lockedByAssets = typeSelect.dataset.lockedByAssets === "1";

    let savedType = typeSelect.value;
    let savedCategory = categorySelect ? categorySelect.value : "";
    let savedHandover = handoverCheckbox ? handoverCheckbox.checked : false;
    let wasConsumable = toggle.checked;

    function apply() {
        const consumable = toggle.checked;
        if (consumable && !wasConsumable) {
            savedType = typeSelect.value;
            savedCategory = categorySelect ? categorySelect.value : "";
            savedHandover = handoverCheckbox ? handoverCheckbox.checked : false;
        }
        if (!lockedByAssets) {
            typeSelect.disabled = consumable;
            typeSelect.required = !consumable;
            if (consumable) {
                typeSelect.value = "";
            } else if (!typeSelect.value && savedType) {
                typeSelect.value = savedType;
            }
        }
        // Kategorieoptionen zuerst an den (ggf. wiederhergestellten) Assettyp anpassen
        typeSelect.dispatchEvent(new Event("change"));
        if (categorySelect) {
            if (consumable) {
                categorySelect.value = "";
                categorySelect.disabled = true;
            } else {
                categorySelect.disabled = false;
                if (!categorySelect.value && savedCategory) {
                    categorySelect.value = savedCategory;
                }
            }
        }
        if (handoverCheckbox) {
            if (consumable) {
                handoverCheckbox.checked = false;
                handoverCheckbox.disabled = true;
            } else {
                handoverCheckbox.disabled = false;
                if (wasConsumable) {
                    handoverCheckbox.checked = savedHandover;
                }
            }
        }
        if (stockRow) { stockRow.hidden = !consumable; }
        if (nameInput) {
            nameInput.placeholder = consumable ? "z. B. Toner CF259A (26A) schwarz" : "z. B. ThinkPad T14 Gen 5";
        }
        if (hint) { hint.hidden = !consumable; }
        if (requiredMarker) { requiredMarker.hidden = consumable; }
        wasConsumable = consumable;
    }

    toggle.addEventListener("change", apply);
    apply();
})();

