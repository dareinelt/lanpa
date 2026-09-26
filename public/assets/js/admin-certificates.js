/*
 * Adminbereich Zertifikate: CSR kopieren und Vorschau-Overlay beim Import.
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-cert-copy]').forEach(function (button) {
        var source = document.getElementById(button.getAttribute('data-cert-copy') || '');
        if (!source || !navigator.clipboard) {
            return;
        }

        var label = button.textContent;
        button.hidden = false;
        button.addEventListener('click', function () {
            navigator.clipboard.writeText(source.value).then(function () {
                button.textContent = 'Kopiert';
                window.setTimeout(function () {
                    button.textContent = label;
                }, 2000);
            }, function () {
                source.select();
            });
        });
    });

    var fileInput = document.getElementById('certificate_file');
    var textInput = document.getElementById('certificate_text');
    if (fileInput && textInput) {
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files.length > 0) {
                textInput.value = '';
            }
        });
    }

    var overlay = document.querySelector('[data-cert-overlay]');
    if (!overlay) {
        return;
    }

    var cancel = overlay.querySelector('[data-cert-overlay-cancel]');
    document.body.classList.add('has-nav-tree-overlay');

    var focusable = function () {
        return Array.prototype.filter.call(
            overlay.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), summary'),
            function (node) { return node.offsetParent !== null; }
        );
    };

    var initial = overlay.querySelector('[data-cert-overlay-focus]');
    if (initial) {
        initial.focus();
    }

    overlay.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && cancel) {
            event.preventDefault();
            cancel.click();
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        var nodes = focusable();
        if (nodes.length === 0) {
            return;
        }

        var first = nodes[0];
        var last = nodes[nodes.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
}());
