/*
 * Vorschlaege fuer AD-Gruppennamen (kommagetrennte Eingabe).
 *
 * Quelle ist ausschliesslich der lokal synchronisierte Datenbestand
 * (/admin/ad/gruppen) – das AD selbst wird dabei nicht abgefragt.
 * Umsetzung als ARIA-Combobox: Inline-Ergaenzung waehrend des Tippens und
 * eine Liste unterhalb des Eingabefelds.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 150;

    function init(input) {
        var url = input.getAttribute('data-group-suggest');
        if (!url) {
            return;
        }

        var listId = input.id + '-vorschlaege';
        var list = document.createElement('ul');
        list.id = listId;
        list.className = 'group-suggest__list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', 'Vorgeschlagene AD-Gruppen');
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
        input.setAttribute('aria-autocomplete', 'both');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', listId);

        var items = [];
        var active = -1;
        var timer = null;
        var requestId = 0;
        var cache = {};
        var lastInputType = '';

        // Aktuelles Token (Text zwischen den Kommata um die Schreibmarke).
        function currentToken() {
            var value = input.value;
            var caret = input.selectionStart === null ? value.length : input.selectionStart;
            var start = value.lastIndexOf(',', caret - 1) + 1;
            var end = value.indexOf(',', caret);
            if (end === -1) {
                end = value.length;
            }
            var raw = value.slice(start, end);
            var lead = raw.length - raw.replace(/^\s+/, '').length;

            return {
                start: start + lead,
                end: end,
                text: value.slice(start + lead, caret),
                full: raw.trim()
            };
        }

        function chosenNames(exceptStart) {
            var names = {};
            var offset = 0;
            input.value.split(',').forEach(function (part) {
                var partStart = offset + (part.length - part.replace(/^\s+/, '').length);
                offset += part.length + 1;
                var name = part.trim().toLowerCase();
                if (name !== '' && partStart !== exceptStart) {
                    names[name] = true;
                }
            });

            return names;
        }

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
            options.forEach(function (option, i) {
                var selected = i === active;
                option.setAttribute('aria-selected', selected ? 'true' : 'false');
                option.classList.toggle('is-active', selected);
                if (selected) {
                    input.setAttribute('aria-activedescendant', option.id);
                    option.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        function render(found, token) {
            var chosen = chosenNames(token.start);
            items = found.filter(function (item) {
                return !chosen[String(item.name).toLowerCase()];
            });

            list.innerHTML = '';
            active = -1;
            input.removeAttribute('aria-activedescendant');

            if (items.length === 0) {
                list.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                status.textContent = token.text === '' ? '' : 'Keine passende AD-Gruppe im synchronisierten Datenbestand.';
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
                name.textContent = item.name;
                option.appendChild(name);

                var meta = [];
                if (item.description) {
                    meta.push(item.description);
                }
                if (typeof item.members === 'number') {
                    meta.push(item.members === 1 ? '1 Mitglied' : item.members + ' Mitglieder');
                }
                if (item.source === 'vergeben') {
                    meta.push('bereits vergeben, nicht im AD-Datenbestand');
                }
                if (meta.length > 0) {
                    var hint = document.createElement('span');
                    hint.className = 'group-suggest__meta';
                    hint.textContent = meta.join(' · ');
                    option.appendChild(hint);
                }

                option.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                });
                option.addEventListener('click', function () {
                    choose(index);
                });
                list.appendChild(option);
            });

            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            status.textContent = items.length === 1 ? '1 Vorschlag verfügbar.' : items.length + ' Vorschläge verfügbar.';

            inlineComplete(token);
        }

        // Ergaenzt den ersten passenden Namen markiert hinter der Schreibmarke.
        function inlineComplete(token) {
            if (lastInputType !== 'insertText' || token.text === '' || items.length === 0) {
                return;
            }
            var caret = input.selectionStart;
            if (caret !== input.selectionEnd || caret !== token.start + token.text.length || token.end !== caret) {
                return;
            }
            var name = String(items[0].name);
            if (name.toLowerCase().indexOf(token.text.toLowerCase()) !== 0 || name.length === token.text.length) {
                return;
            }
            var value = input.value;
            input.value = value.slice(0, token.start) + token.text + name.slice(token.text.length) + value.slice(token.end);
            input.setSelectionRange(caret, token.start + name.length);
            setActive(0);
        }

        function choose(index) {
            var item = items[index];
            if (!item) {
                return;
            }
            var token = currentToken();
            var value = input.value;
            var before = value.slice(0, token.start);
            var after = value.slice(token.end).replace(/^\s*,?\s*/, '');
            var insert = item.name + ', ';
            input.value = before + insert + after;
            var caret = before.length + insert.length;
            input.setSelectionRange(caret, caret);
            status.textContent = item.name + ' übernommen.';
            close();
            input.focus();
        }

        function load() {
            var token = currentToken();
            var key = token.text.toLowerCase();
            if (cache[key]) {
                render(cache[key], token);
                return;
            }

            var id = ++requestId;
            fetch(url + '?q=' + encodeURIComponent(token.text), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            })
                .then(function (response) {
                    return response.ok ? response.json() : { items: [] };
                })
                .then(function (data) {
                    var found = Array.isArray(data.items) ? data.items : [];
                    cache[key] = found;
                    if (id === requestId && document.activeElement === input) {
                        render(found, currentToken());
                    }
                })
                .catch(function () {
                    close();
                });
        }

        function schedule() {
            window.clearTimeout(timer);
            timer = window.setTimeout(load, DEBOUNCE_MS);
        }

        input.addEventListener('input', function (event) {
            lastInputType = event.inputType || 'insertText';
            schedule();
        });

        input.addEventListener('focus', function () {
            lastInputType = '';
            schedule();
        });

        input.addEventListener('click', function () {
            lastInputType = '';
            schedule();
        });

        input.addEventListener('keydown', function (event) {
            var open = !list.hidden && items.length > 0;
            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    if (!open) {
                        lastInputType = '';
                        load();
                        return;
                    }
                    setActive(active + 1);
                    break;
                case 'ArrowUp':
                    if (open) {
                        event.preventDefault();
                        setActive(active - 1);
                    }
                    break;
                case 'Enter':
                    if (open && active >= 0) {
                        event.preventDefault();
                        choose(active);
                    }
                    break;
                case 'Tab':
                    // Tab uebernimmt nur eine sichtbare Inline-Ergaenzung.
                    if (open && active >= 0 && input.selectionStart !== input.selectionEnd && !event.shiftKey) {
                        event.preventDefault();
                        choose(active);
                    }
                    break;
                case 'Escape':
                    if (open) {
                        event.preventDefault();
                        if (input.selectionStart !== input.selectionEnd) {
                            // Inline-Ergaenzung verwerfen.
                            var start = input.selectionStart;
                            input.value = input.value.slice(0, start) + input.value.slice(input.selectionEnd);
                            input.setSelectionRange(start, start);
                        }
                        close();
                    }
                    break;
                default:
                    break;
            }
        });

        input.addEventListener('blur', function () {
            window.setTimeout(close, 100);
        });
    }

    document.querySelectorAll('input[data-group-suggest]').forEach(init);
})();
