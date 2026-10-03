/*
 * Adminbereich Speicher (HA) – Speicherziel bearbeiten: zeigt nur die Felder
 * der gewählten Art (SMB-Freigabe oder S3-Objektspeicher). Ohne JavaScript
 * bleiben beide Bereiche sichtbar; der Server wertet nur die gewählte Art aus.
 */
(function () {
    'use strict';

    var select = document.querySelector('[data-storage-kind]');
    if (!select) {
        return;
    }
    var sections = document.querySelectorAll('[data-kind-section]');

    function update() {
        Array.prototype.forEach.call(sections, function (section) {
            section.hidden = section.getAttribute('data-kind-section') !== select.value;
        });
    }

    select.addEventListener('change', update);
    update();
}());

/*
 * Cold-Tiers erweitern: Vorschau der neuen Erweiterung je Tier und
 * Zugangsdaten-Felder ausgrauen, wenn die des Basisziels übernommen werden.
 */
(function () {
    'use strict';

    Array.prototype.forEach.call(document.querySelectorAll('[data-plan-source]'), function (input) {
        var target = document.querySelector('[data-plan-label="' + input.getAttribute('data-plan-source') + '"]');
        if (!target) {
            return;
        }
        input.addEventListener('input', function () {
            target.textContent = input.value.trim() !== '' ? input.value : '…';
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('fieldset[data-tier]'), function (fieldset) {
        var reuse = fieldset.querySelector('input[type="checkbox"][name$="[reuse_credentials]"]');
        if (!reuse) {
            return;
        }
        var fields = fieldset.querySelectorAll('[name$="[username]"], [name$="[domain]"], [name$="[password]"], [name$="[s3_access_key]"], [name$="[s3_secret_key]"]');
        function update() {
            Array.prototype.forEach.call(fields, function (field) {
                field.disabled = reuse.checked;
            });
        }
        reuse.addEventListener('change', update);
        update();
    });
}());
