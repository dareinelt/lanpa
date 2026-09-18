(function () {
    'use strict';

    // 1. Schieber für Baum-/Listenansicht: Formular bei Änderung automatisch absenden.
    var toggle = document.querySelector('[data-auto-submit]');
    if (toggle) {
        toggle.addEventListener('change', function () {
            var form = toggle.closest('form');
            if (form) {
                form.submit();
            }
        });
    }

    // 2. Plus-Schaltflächen: Menü zum Anlegen eines Unterelements öffnen/schließen.
    function closeAllMenus(except) {
        var menus = document.querySelectorAll('[data-add-menu]');
        for (var i = 0; i < menus.length; i++) {
            var menu = menus[i];
            if (menu !== except && !menu.hidden) {
                menu.hidden = true;
                var row = menu.closest('.tree-node');
                if (row) {
                    var button = row.querySelector('[data-add-button]');
                    if (button) {
                        button.setAttribute('aria-expanded', 'false');
                    }
                }
            }
        }
    }

    document.addEventListener('click', function (event) {
        var addButton = event.target.closest('[data-add-button]');
        if (addButton) {
            event.stopPropagation();
            var node = addButton.closest('.tree-node');
            var menu = node ? node.querySelector('[data-add-menu]') : null;
            if (!menu) {
                return;
            }
            var willOpen = menu.hidden;
            closeAllMenus(menu);
            menu.hidden = !willOpen;
            addButton.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            return;
        }

        if (!event.target.closest('[data-add-menu]')) {
            closeAllMenus(null);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAllMenus(null);
            closeOverlay();
        }
    });

    // 3. Overlay zum Anlegen einer Textseite.
    var overlay = document.querySelector('[data-page-overlay]');
    var overlayForm = document.querySelector('[data-overlay-form]');
    var overlayParentHint = document.querySelector('[data-overlay-parent]');
    var overlayParentId = document.querySelector('[data-overlay-parent-id]');

    function closeOverlay() {
        if (overlay && !overlay.hidden) {
            overlay.hidden = true;
            document.body.classList.remove('has-nav-tree-overlay');
        }
    }

    function openOverlay(parentId, parentTitle) {
        if (!overlay) {
            return;
        }

        if (overlayForm) {
            overlayForm.reset();
        }

        if (overlayParentId) {
            overlayParentId.value = parentId || '0';
        }

        var overlaySurface = overlayForm ? overlayForm.querySelector('[data-editor-surface]') : null;
        if (overlaySurface) {
            overlaySurface.innerHTML = '';
        }

        if (overlayParentHint) {
            if (parentTitle) {
                overlayParentHint.textContent = 'Wird unter „' + parentTitle + '“ angelegt.';
                overlayParentHint.hidden = false;
            } else {
                overlayParentHint.textContent = '';
                overlayParentHint.hidden = true;
            }
        }

        if (overlayForm) {
            var titleInput = overlayForm.querySelector('input[name="title"]');
            if (titleInput) {
                titleInput.focus();
            }
        }

        overlay.hidden = false;
        document.body.classList.add('has-nav-tree-overlay');
    }

    document.addEventListener('click', function (event) {
        var pageButton = event.target.closest('[data-add-page]');
        if (pageButton) {
            closeAllMenus(null);
            openOverlay(
                pageButton.getAttribute('data-parent-id') || '0',
                pageButton.getAttribute('data-parent-title') || ''
            );
            return;
        }

        if (event.target.closest('[data-overlay-close]')) {
            closeOverlay();
            return;
        }

        if (overlay && !overlay.hidden && event.target === overlay) {
            closeOverlay();
        }
    });

    // 4. Editor im Overlay initialisieren.
    var surface = overlayForm ? overlayForm.querySelector('[data-editor-surface]') : null;
    var source = overlayForm ? overlayForm.querySelector('[data-editor-source]') : null;

    if (surface && source) {
        surface.innerHTML = source.value;

        var editor = overlayForm.querySelector('[data-editor]');
        if (editor) {
            editor.addEventListener('click', function (event) {
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

        overlayForm.addEventListener('submit', function () {
            source.value = surface.innerHTML;
        });
    }
})();
