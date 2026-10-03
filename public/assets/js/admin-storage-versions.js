/*
 * Adminbereich Dateiversionen: Ja/Nein-Bestaetigung vor der Wiederherstellung.
 * Ohne JavaScript wird das Formular direkt abgeschickt; der Server verlangt
 * dann weiterhin CSRF-Token und Adminrechte.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('restore-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }
    var text = document.getElementById('restore-dialog-text');
    var yes = document.getElementById('restore-dialog-yes');
    var no = document.getElementById('restore-dialog-no');
    var pending = null;

    function close() {
        pending = null;
        if (dialog.open) {
            dialog.close();
        }
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.hasAttribute('data-restore-form') || form.dataset.confirmed === '1') {
            return;
        }
        event.preventDefault();
        var button = form.querySelector('button[data-restore-path]');
        var path = button ? button.getAttribute('data-restore-path') : '';
        var version = button ? button.getAttribute('data-restore-version') : '';
        var deleted = button && button.getAttribute('data-restore-deleted') === '1';
        pending = form;
        // Inhalte werden ueber textContent gesetzt, nie als HTML.
        text.textContent = (deleted
            ? 'Die gelöschte Datei „' + path + '“ wird aus Version ' + version + ' wieder angelegt.'
            : 'Datei „' + path + '“ auf Version ' + version + ' zurücksetzen?');
        dialog.showModal();
        no.focus();
    });

    yes.addEventListener('click', function () {
        var form = pending;
        close();
        if (form) {
            form.dataset.confirmed = '1';
            yes.disabled = true;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        }
    });
    no.addEventListener('click', close);
    dialog.addEventListener('cancel', function () {
        pending = null;
    });
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            close();
        }
    });
}());
