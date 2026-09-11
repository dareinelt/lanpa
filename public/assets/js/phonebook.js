/* Telefonliste: entprellte Suche gegen /api/telefonliste. */
(function () {
    'use strict';

    var DEBOUNCE_MS = 250;
    var PAGE_SIZE = 25;

    var form = document.querySelector('[data-phonebook-form]');
    var results = document.querySelector('[data-phonebook-results]');
    var emergency = document.querySelector('[data-phonebook-emergency]');
    var status = document.querySelector('[data-phonebook-status]');
    var moreButton = document.querySelector('[data-phonebook-more]');
    var template = document.querySelector('[data-phonebook-template]');

    if (!form || !results || !template) {
        return;
    }

    var input = form.querySelector('input[name="q"]');
    var timer = null;
    var controller = null;
    var offset = 0;
    var currentTerm = '';
    var searchStarted = false;
    var initialStatus = status ? status.textContent : '';
    var phoneClickable = results.getAttribute('data-phone-clickable') !== '0';

    function startSearch() {
        if (searchStarted) {
            return;
        }
        searchStarted = true;
        if (emergency) {
            emergency.hidden = true;
        }
        results.hidden = false;
    }

    function resetSearch() {
        window.clearTimeout(timer);

        if (controller) {
            controller.abort();
            controller = null;
        }

        searchStarted = false;
        currentTerm = '';
        offset = 0;
        results.textContent = '';
        results.hidden = true;

        if (moreButton) {
            moreButton.hidden = true;
        }

        if (emergency) {
            emergency.hidden = false;
        }

        setStatus(initialStatus);
    }

    function setStatus(message) {
        if (status) {
            status.textContent = message;
        }
    }

    function renderPerson(person) {
        var node = template.content.firstElementChild.cloneNode(true);

        node.querySelector('[data-field="display_name"]').textContent = person.display_name;
        node.querySelector('[data-field="department"]').textContent = person.department || '–';

        var phone = node.querySelector('[data-field="phone"]');
        if (person.phone) {
            phone.textContent = person.phone;
            if (phoneClickable) {
                phone.setAttribute('href', 'tel:' + person.phone.replace(/[^\d+]/g, ''));
            } else {
                phone.removeAttribute('href');
            }
        } else {
            var parent = phone.parentNode;
            parent.textContent = '–';
        }

        var mobile = person.mobile;
        if (mobile && person.phone && normalizeNumber(mobile) === normalizeNumber(person.phone)) {
            mobile = '';
        }

        setOptionalLink(node, 'mobile', mobile, 'tel:');
        setOptionalLink(node, 'email', person.email, 'mailto:');

        return node;
    }

    function normalizeNumber(value) {
        return String(value).replace(/[^\d+]/g, '');
    }

    function setOptionalLink(node, field, value, scheme) {
        var row = node.querySelector('[data-row="' + field + '"]');
        if (!row) {
            return;
        }

        if (!value) {
            row.hidden = true;
            return;
        }

        var link = row.querySelector('[data-field="' + field + '"]');
        link.textContent = value;
        if (scheme === 'tel:' && !phoneClickable) {
            link.removeAttribute('href');
        } else {
            link.setAttribute('href', scheme + (scheme === 'tel:' ? value.replace(/[^\d+]/g, '') : value));
        }
        row.hidden = false;
    }

    function render(data, append) {
        if (!append) {
            results.textContent = '';
        }

        if (data.items.length === 0 && !append) {
            var empty = document.createElement('p');
            empty.className = 'empty-state';
            empty.textContent = 'Keine Einträge gefunden.';
            results.appendChild(empty);
        }

        var fragment = document.createDocumentFragment();
        data.items.forEach(function (person) {
            fragment.appendChild(renderPerson(person));
        });
        results.appendChild(fragment);

        if (moreButton) {
            moreButton.hidden = !data.has_more;
        }

        offset = data.offset + data.items.length;
        setStatus(data.total === 0
            ? 'Keine Treffer.'
            : 'Treffer: ' + data.total + ' – angezeigt: ' + Math.min(offset, data.total));
    }

    function load(term, append) {
        startSearch();

        if (controller) {
            controller.abort();
        }
        controller = typeof AbortController === 'function' ? new AbortController() : null;

        var params = new URLSearchParams();
        params.set('q', term);
        params.set('limit', String(PAGE_SIZE));
        params.set('offset', String(append ? offset : 0));

        setStatus('Suche läuft …');

        fetch('/api/telefonliste?' + params.toString(), {
            headers: { 'Accept': 'application/json' },
            signal: controller ? controller.signal : undefined
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Anfrage fehlgeschlagen');
                }
                return response.json();
            })
            .then(function (data) {
                render(data, append);
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                setStatus('Die Telefonliste ist derzeit nicht erreichbar.');
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        currentTerm = input ? input.value.trim() : '';
        offset = 0;
        load(currentTerm, false);
    });

    if (input) {
        input.addEventListener('input', function () {
            window.clearTimeout(timer);

            if (input.value.trim() === '') {
                resetSearch();
                return;
            }

            timer = window.setTimeout(function () {
                currentTerm = input.value.trim();
                offset = 0;
                load(currentTerm, false);
            }, DEBOUNCE_MS);
        });
    }

    if (moreButton) {
        moreButton.addEventListener('click', function () {
            load(currentTerm, true);
        });
    }
})();
