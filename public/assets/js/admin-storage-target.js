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
