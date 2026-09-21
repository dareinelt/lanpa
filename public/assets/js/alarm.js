/* Einzelversand-Opt-In: abweichende Gateway-Daten nur bei aktivem Haken zeigen. */
(function () {
    'use strict';

    var form = document.querySelector('[data-single-form]');
    if (!form) {
        return;
    }

    var toggle = form.querySelector('[data-single-toggle]');
    var fields = form.querySelectorAll('[data-single-field]');

    function sync() {
        var show = toggle ? toggle.checked : false;
        for (var i = 0; i < fields.length; i++) {
            fields[i].hidden = !show;
        }
    }

    if (toggle) {
        toggle.addEventListener('change', sync);
    }

    sync();
})();
