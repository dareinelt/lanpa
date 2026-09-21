/* Admin-Telefonliste: Ein-/Ausblenden ohne Seiten-Neuladen, damit die
   Seite nicht nach jeder Änderung nach oben springt. */
(function () {
    'use strict';

    function showFlash(text, type) {
        var main = document.querySelector('.admin-main');
        if (!main) {
            return;
        }

        var stack = main.querySelector('.flash-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'flash-stack';
            stack.setAttribute('role', 'status');
            stack.setAttribute('aria-live', 'polite');

            var title = main.querySelector('.admin-title');
            if (title && title.nextSibling) {
                main.insertBefore(stack, title.nextSibling);
            } else {
                main.appendChild(stack);
            }
        }

        var node = document.createElement('p');
        node.className = 'flash flash--' + type;
        node.textContent = text;
        stack.appendChild(node);

        window.setTimeout(function () {
            if (node.parentNode) {
                node.parentNode.removeChild(node);
            }
        }, 5000);
    }

    var forms = document.querySelectorAll('[data-phonebook-toggle]');

    Array.prototype.forEach.call(forms, function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var button = form.querySelector('[data-phonebook-toggle-button]');
            var row = form.closest('tr');
            var badge = row ? row.querySelector('[data-phonebook-visible]') : null;
            var visibleInput = form.querySelector('input[name="visible"]');
            var token = form.querySelector('input[name="_token"]');
            var id = form.querySelector('input[name="id"]');
            var q = form.querySelector('input[name="q"]');

            if (button) {
                button.disabled = true;
            }

            var body = new URLSearchParams();
            body.set('_token', token ? token.value : '');
            body.set('id', id ? id.value : '');
            body.set('visible', visibleInput ? visibleInput.value : '1');
            body.set('q', q ? q.value : '');

            fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: body.toString()
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { response: response, data: data };
                    });
                })
                .then(function (result) {
                    var data = result.data;

                    if (!result.response.ok || !data.ok) {
                        throw new Error(data.message || 'Eintrag nicht gefunden.');
                    }

                    var nowVisible = !!data.visible;

                    if (badge) {
                        badge.className = 'badge ' + (nowVisible ? 'badge--ok' : 'badge--muted');
                        badge.textContent = nowVisible ? 'eingeblendet' : 'ausgeblendet';
                        badge.setAttribute('data-phonebook-visible', nowVisible ? '1' : '0');
                    }

                    if (visibleInput) {
                        visibleInput.value = nowVisible ? '0' : '1';
                    }

                    if (button) {
                        button.textContent = nowVisible ? 'Ausblenden' : 'Einblenden';
                    }

                    showFlash(data.message, 'success');
                })
                .catch(function (error) {
                    showFlash(error && error.message ? error.message : 'Die Änderung konnte nicht gespeichert werden.', 'error');
                })
                .then(function () {
                    if (button) {
                        button.disabled = false;
                    }
                });
        });
    });

    var filterForm = document.querySelector('[data-phonebook-filter-form]');

    if (filterForm) {
        Array.prototype.forEach.call(filterForm.querySelectorAll('[data-phonebook-filter]'), function (input) {
            input.addEventListener('change', function () {
                filterForm.submit();
            });
        });
    }
})();
