(function () {
    'use strict';

    var form = document.querySelector('[data-editor-form]');
    if (!form) {
        return;
    }

    var typeSelect = form.querySelector('[data-editor-type]');
    var urlField = form.querySelector('[data-editor-url]');
    var parentField = form.querySelector('[data-editor-parent]');
    var contentField = form.querySelector('[data-editor-content]');
    var alarmFields = form.querySelectorAll('[data-editor-alarm]');
    var proxyFields = form.querySelectorAll('[data-editor-proxy]');
    var proxyToggle = form.querySelector('[data-proxy-toggle]');
    var proxyNetworkField = form.querySelector('[data-proxy-networks]');

    function syncProxyNetworks() {
        if (proxyNetworkField) {
            proxyNetworkField.hidden = !(proxyToggle && proxyToggle.checked);
        }
    }

    function syncFields() {
        var type = typeSelect ? typeSelect.value : 'external';

        if (urlField) {
            urlField.hidden = type !== 'external' && type !== 'internal';
            var urlInput = urlField.querySelector('input');
            if (urlInput) {
                urlInput.required = type === 'external' || type === 'internal';
            }
        }

        if (parentField) {
            parentField.hidden = type !== 'subpage' && type !== 'page' && type !== 'alarm';
        }

        if (contentField) {
            contentField.hidden = type !== 'page';
        }

        for (var i = 0; i < alarmFields.length; i++) {
            alarmFields[i].hidden = type !== 'alarm';
        }

        for (var j = 0; j < proxyFields.length; j++) {
            proxyFields[j].hidden = type !== 'external';
        }

        syncProxyNetworks();
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', syncFields);
    }
    if (proxyToggle) {
        proxyToggle.addEventListener('change', syncProxyNetworks);
    }
    syncFields();

    var overrideToggle = form.querySelector('[data-tile-override-toggle]');
    var overrideFields = form.querySelectorAll('[data-tile-override]');

    function syncOverride() {
        var show = overrideToggle ? overrideToggle.checked : false;
        for (var i = 0; i < overrideFields.length; i++) {
            overrideFields[i].hidden = !show;
        }
    }

    if (overrideToggle) {
        overrideToggle.addEventListener('change', syncOverride);
    }
    syncOverride();

    var surface = form.querySelector('[data-editor-surface]');
    var source = form.querySelector('[data-editor-source]');

    if (surface && source) {
        surface.innerHTML = source.value;

        var toolbar = form.querySelector('[data-editor]');
        if (toolbar) {
            toolbar.addEventListener('click', function (event) {
                var button = event.target.closest('[data-command]');
                if (!button) {
                    return;
                }

                event.preventDefault();
                surface.focus();

                var command = button.getAttribute('data-command');
                var value = button.getAttribute('data-value') || null;

                if (command === 'createLink') {
                    var url = window.prompt('Link-Adresse (https://… oder /pfad):');
                    if (url === null) {
                        return;
                    }
                    document.execCommand('createLink', false, url);
                    return;
                }

                document.execCommand(command, false, value);
            });
        }

        form.addEventListener('submit', function () {
            source.value = surface.innerHTML;
        });
    }
})();
