/*
 * Admin "Office → SMTP-/IMAP-Proxy": Autovervollstaendigung fuer die
 * Zuordnung AD-Benutzer → Postfach.
 *
 * Links werden synchronisierte AD-Benutzer der gewaehlten Identitaetsquelle
 * vorgeschlagen, rechts nur aktive, noch nicht vergebene Postfaecher dieser
 * Quelle (Filterung serverseitig). Gespeichert wird ausschliesslich die
 * interne ID im versteckten Feld – ein frei getippter Anzeigename reicht
 * nicht. Muster wie admin-group-autocomplete.js (ARIA-Combobox).
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 150;
    var counter = 0;

    function init(root, input) {
        var kind = input.getAttribute('data-mp-suggest');
        var form = input.form;
        var hidden = form ? form.querySelector('input[type="hidden"][name="' + input.getAttribute('data-mp-target') + '"]') : null;
        var baseUrl = root.getAttribute(kind === 'users' ? 'data-users-url' : 'data-mailboxes-url');
        if (!hidden || !baseUrl) {
            return;
        }
        var mappingId = form.getAttribute('data-mail-proxy-mapping') || '0';

        counter += 1;
        var listId = 'mp-suggest-' + counter;
        var list = document.createElement('ul');
        list.id = listId;
        list.className = 'group-suggest__list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', kind === 'users' ? 'Vorgeschlagene AD-Benutzer' : 'Vorgeschlagene Postfächer');
        list.hidden = true;

        var status = document.createElement('p');
        status.className = 'visually-hidden';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');

        var control = document.createElement('div');
        control.className = 'group-suggest__control';
        input.parentNode.insertBefore(control, input);
        control.appendChild(input);
        control.appendChild(list);
        control.insertAdjacentElement('afterend', status);

        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', listId);

        var items = [];
        var active = -1;
        var timer = null;
        var requestId = 0;
        var chosenLabel = hidden.value !== '' ? input.value : null;

        function close() {
            list.hidden = true;
            list.innerHTML = '';
            items = [];
            active = -1;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        function setActive(index) {
            var options = list.querySelectorAll('[role="option"]');
            if (options.length === 0) {
                active = -1;
                return;
            }
            active = (index + options.length) % options.length;
            Array.prototype.forEach.call(options, function (option, i) {
                var selected = i === active;
                option.setAttribute('aria-selected', selected ? 'true' : 'false');
                option.classList.toggle('is-active', selected);
                if (selected) {
                    input.setAttribute('aria-activedescendant', option.id);
                    option.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        function metaFor(item) {
            var meta = [];
            if (kind === 'users') {
                meta.push(item.username);
                if (item.source) {
                    meta.push(item.source);
                }
                if (item.department) {
                    meta.push(item.department);
                }
                if (item.mapped) {
                    meta.push('bereits zugeordnet');
                }
            } else {
                meta.push(item.username);
                if (item.display_name) {
                    meta.push(item.display_name);
                }
            }

            return meta.filter(function (part) { return part; }).join(' · ');
        }

        function render(found, term) {
            items = found;
            list.innerHTML = '';
            active = -1;
            input.removeAttribute('aria-activedescendant');

            if (items.length === 0) {
                list.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                status.textContent = term === '' ? '' : (kind === 'users'
                    ? 'Kein passender aktiver AD-Benutzer in dieser Identitätsquelle.'
                    : 'Kein freies, aktives Postfach in dieser Identitätsquelle.');
                return;
            }

            items.forEach(function (item, index) {
                var option = document.createElement('li');
                option.id = listId + '-' + index;
                option.className = 'group-suggest__option';
                option.setAttribute('role', 'option');
                option.setAttribute('aria-selected', 'false');

                var name = document.createElement('span');
                name.className = 'group-suggest__name';
                name.textContent = item.label;
                option.appendChild(name);

                var metaText = metaFor(item);
                if (metaText !== '') {
                    var meta = document.createElement('span');
                    meta.className = 'group-suggest__meta';
                    meta.textContent = metaText;
                    option.appendChild(meta);
                }

                option.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    choose(index);
                });
                list.appendChild(option);
            });

            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            status.textContent = items.length === 1 ? '1 Vorschlag verfügbar.' : items.length + ' Vorschläge verfügbar.';
        }

        function choose(index) {
            var item = items[index];
            if (!item) {
                return;
            }
            hidden.value = String(item.id);
            input.value = item.label;
            chosenLabel = item.label;
            input.setCustomValidity('');
            close();
        }

        function load() {
            var term = input.value.trim();
            var url = baseUrl + '&q=' + encodeURIComponent(term);
            if (kind === 'mailboxes') {
                url += '&zuordnung=' + encodeURIComponent(mappingId);
            }
            var current = ++requestId;
            fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (response) {
                    return response.ok ? response.json() : { items: [] };
                })
                .then(function (data) {
                    if (current === requestId && document.activeElement === input) {
                        render(Array.isArray(data.items) ? data.items : [], term);
                    }
                })
                .catch(function () {
                    if (current === requestId) {
                        close();
                    }
                });
        }

        function schedule() {
            window.clearTimeout(timer);
            timer = window.setTimeout(load, DEBOUNCE_MS);
        }

        input.addEventListener('input', function () {
            // Frei getippter Text ist keine gueltige Auswahl.
            if (chosenLabel === null || input.value !== chosenLabel) {
                hidden.value = '';
            }
            schedule();
        });
        input.addEventListener('focus', schedule);
        input.addEventListener('blur', function () {
            window.setTimeout(close, 120);
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (list.hidden) {
                    load();
                } else {
                    setActive(active + 1);
                }
            } else if (event.key === 'ArrowUp') {
                if (!list.hidden) {
                    event.preventDefault();
                    setActive(active - 1);
                }
            } else if (event.key === 'Enter') {
                if (!list.hidden && active >= 0) {
                    event.preventDefault();
                    choose(active);
                }
            } else if (event.key === 'Escape') {
                if (!list.hidden) {
                    event.preventDefault();
                    close();
                }
            }
        });

        if (form && !form.hasAttribute('data-mp-validated')) {
            form.setAttribute('data-mp-validated', '1');
            form.addEventListener('submit', function (event) {
                var invalid = false;
                Array.prototype.forEach.call(form.querySelectorAll('[data-mp-suggest]'), function (field) {
                    var target = form.querySelector('input[type="hidden"][name="' + field.getAttribute('data-mp-target') + '"]');
                    if (target && target.value === '') {
                        field.setCustomValidity(field.getAttribute('data-mp-suggest') === 'users'
                            ? 'Bitte einen AD-Benutzer aus den Vorschlägen auswählen.'
                            : 'Bitte ein Postfach aus den Vorschlägen auswählen.');
                        if (!invalid) {
                            field.reportValidity();
                        }
                        invalid = true;
                    } else {
                        field.setCustomValidity('');
                    }
                });
                if (invalid) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-mail-proxy-map]'), function (root) {
            Array.prototype.forEach.call(root.querySelectorAll('[data-mp-suggest]'), function (input) {
                init(root, input);
            });
        });
    });
})();

/*
 * SMTP-/IMAP-Proxy: Konfiguration und Postfach im Overlay bearbeiten. Der
 * Server rendert die Uebersicht samt Overlay (Bearbeiten-Links,
 * Validierungsfehler); hier wird es modal geoeffnet. Escape fuehrt wie
 * "Abbrechen" zurueck zur Uebersicht. Ohne dialog-Unterstuetzung bleibt das
 * Overlay offen im Fluss.
 */
(function () {
    'use strict';

    var target = '/admin/office/mail-proxy';

    Array.prototype.forEach.call(
        document.querySelectorAll('[data-mail-proxy-server-dialog], [data-mail-proxy-mailbox-dialog]'),
        function (dialog) {
            if (typeof dialog.showModal !== 'function') {
                return;
            }
            if (dialog.open) {
                dialog.close();
            }
            dialog.showModal();

            var first = dialog.querySelector('[aria-invalid="true"]')
                || dialog.querySelector('form input:not([type="hidden"]), form textarea, form select');
            if (first) {
                first.focus();
            }

            dialog.addEventListener('cancel', function (event) {
                event.preventDefault();
                window.location.assign(target);
            });
        }
    );
}());
