/* Landingpage: Detailbeschreibungen aufklappen und Klicks erfassen. */
(function () {
    'use strict';

    // Referenz auf die Alarm-Overlay-Oeffnungsfunktion, damit geschuetzte
    // Alarm-Kacheln nach der SMS-Freischaltung die Bestaetigung oeffnen koennen.
    var openAlarm = null;

    function initToggles() {
        document.addEventListener('click', function (event) {
            var toggle = event.target.closest('.tile__toggle');
            if (!toggle) {
                return;
            }

            var tile = toggle.closest('.tile');
            if (!tile) {
                return;
            }

            var isOpen = tile.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            var label = toggle.querySelector('.tile__toggle-label');
            if (label) {
                label.textContent = isOpen ? 'Details ausblenden' : 'Details';
            }
        });
    }

    function trackClick(navigationId) {
        var url = '/api/klick';

        if (navigator.sendBeacon) {
            var data = new FormData();
            data.append('navigation_id', navigationId);
            if (navigator.sendBeacon(url, data)) {
                return;
            }
        }

        // Fallback: Fire-and-forget, blockiert die Navigation nicht.
        try {
            fetch(url, {
                method: 'POST',
                keepalive: true,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ navigation_id: navigationId })
            }).catch(function () { /* Statistik darf nie stören. */ });
        } catch (error) {
            /* ignorieren */
        }
    }

    function initTracking() {
        document.addEventListener('click', function (event) {
            var link = event.target.closest('[data-nav-id]');
            if (!link) {
                return;
            }

            var id = parseInt(link.getAttribute('data-nav-id'), 10);
            if (!isNaN(id) && id > 0) {
                trackClick(id);
            }
        });
    }

    function initAlarms() {
        var overlay = document.querySelector('[data-alarm-overlay]');
        if (!overlay) {
            return;
        }

        var title = overlay.querySelector('#alarm-overlay-title');
        var details = overlay.querySelector('[data-alarm-details]');
        var cancelButton = overlay.querySelector('[data-alarm-cancel]');
        var confirmButton = overlay.querySelector('[data-alarm-confirm]');
        var freetextContainer = overlay.querySelector('.alarm-freetext');
        var freetextToggle = overlay.querySelector('[data-alarm-freetext-toggle]');
        var freetextBody = overlay.querySelector('[data-alarm-freetext-body]');
        var freetextInput = overlay.querySelector('[data-alarm-freetext]');
        var freetextCounter = overlay.querySelector('[data-alarm-freetext-counter]');
        var previewContainer = overlay.querySelector('.alarm-preview');
        var previewMessage = overlay.querySelector('[data-alarm-preview-message]');
        var current = null;
        var done = false;
        var lastTrigger = null;

        var MAX_TOTAL = 255;

        function combinedMessage() {
            var base = current ? (current.text || '') : '';
            var extra = freetextInput ? freetextInput.value : '';
            if (extra === '') {
                return base;
            }
            return base === '' ? extra : base + ' ' + extra;
        }

        function updateFreetext() {
            if (!freetextInput || !freetextCounter || !previewMessage) {
                return;
            }

            var base = current ? (current.text || '') : '';
            var baseLen = base.length;
            // Der Freitext darf zusammen mit dem Leerzeichen und dem Vorlagentext
            // insgesamt 255 Zeichen nicht überschreiten.
            var maxExtra = Math.max(0, MAX_TOTAL - baseLen - (baseLen > 0 ? 1 : 0));
            freetextInput.maxLength = maxExtra;

            var combined = combinedMessage();
            var remaining = Math.max(0, MAX_TOTAL - combined.length);
            freetextCounter.textContent = 'Noch ' + remaining + ' Zeichen';
            if (remaining === 0) {
                freetextCounter.classList.add('is-full');
            } else {
                freetextCounter.classList.remove('is-full');
            }

            previewMessage.textContent = combined === '' ? '—' : combined;
        }

        function resetOverlay() {
            done = false;
            if (title) {
                title.textContent = 'Alarmierung auslösen';
            }
            if (details) {
                details.textContent = '';
            }
            if (cancelButton) {
                cancelButton.hidden = false;
                cancelButton.disabled = false;
            }
            if (confirmButton) {
                confirmButton.textContent = 'Alarmierung auslösen';
                confirmButton.disabled = false;
            }
            if (freetextContainer) {
                freetextContainer.hidden = false;
            }
            if (previewContainer) {
                previewContainer.hidden = false;
            }
            if (freetextToggle) {
                freetextToggle.checked = false;
            }
            if (freetextInput) {
                freetextInput.value = '';
            }
            if (freetextBody) {
                freetextBody.hidden = true;
            }
            updateFreetext();
        }

        function close() {
            overlay.hidden = true;
            document.body.classList.remove('has-announcement-overlay');
            current = null;
            if (lastTrigger) {
                lastTrigger.focus();
                lastTrigger = null;
            }
        }

        function showResult(success, message) {
            done = true;
            if (title) {
                title.textContent = success ? 'Alarmierung ausgelöst' : 'Alarmierung fehlgeschlagen';
            }
            if (details) {
                details.textContent = message || '';
            }
            if (cancelButton) {
                cancelButton.hidden = true;
            }
            if (confirmButton) {
                confirmButton.textContent = 'OK';
                confirmButton.disabled = false;
            }
            if (freetextContainer) {
                freetextContainer.hidden = true;
            }
            if (previewContainer) {
                previewContainer.hidden = true;
            }
        }

        function open(trigger) {
            current = {
                id: trigger.getAttribute('data-alarm-id'),
                title: trigger.getAttribute('data-alarm-title') || '',
                text: trigger.getAttribute('data-alarm-text') || '',
                group: trigger.getAttribute('data-alarm-group') || ''
            };

            resetOverlay();

            var lines = [];
            if (current.title) {
                lines.push('Kachel: ' + current.title);
            }
            if (current.text) {
                lines.push('Text: ' + current.text);
            }
            if (current.group) {
                lines.push('Gruppe: ' + current.group);
            }
            if (details) {
                details.textContent = lines.join('\n');
            }

            lastTrigger = trigger;
            overlay.hidden = false;
            document.body.classList.add('has-announcement-overlay');
            if (confirmButton) {
                confirmButton.focus();
            }
        }

        openAlarm = open;

        function send() {
            if (!current || done) {
                return;
            }

            var meta = document.querySelector('meta[name="csrf-token"]');
            var token = meta ? meta.getAttribute('content') : '';

            if (confirmButton) {
                confirmButton.disabled = true;
                confirmButton.textContent = 'Wird ausgelöst …';
            }
            if (cancelButton) {
                cancelButton.disabled = true;
            }

            var body = new FormData();
            body.append('_token', token);
            body.append('navigation_id', current.id);

            if (freetextToggle && freetextToggle.checked && freetextInput && freetextInput.value.trim() !== '') {
                body.append('additional_text', freetextInput.value);
            }

            fetch('/api/alarm', {
                method: 'POST',
                body: body
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return {};
                    });
                })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        showResult(true, data.message || 'Die Alarmierung wurde ausgelöst.');
                    } else {
                        showResult(false, (data && data.message) || 'Die Alarmierung konnte nicht ausgelöst werden.');
                    }
                })
                .catch(function () {
                    showResult(false, 'Die Alarmierung konnte nicht ausgelöst werden.');
                });
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-alarm-id]');
            // Geschuetzte Alarm-Kacheln werden zuerst durch die SMS-Schranke
            // abgefangen und erst danach wird die Bestaetigung geoeffnet.
            if (trigger && !trigger.hasAttribute('data-protected-id')) {
                event.preventDefault();
                open(trigger);
            }
        });

        if (cancelButton) {
            cancelButton.addEventListener('click', close);
        }

        if (freetextToggle && freetextBody) {
            freetextToggle.addEventListener('change', function () {
                freetextBody.hidden = !freetextToggle.checked;
                updateFreetext();
                if (freetextToggle.checked && freetextInput) {
                    freetextInput.focus();
                }
            });
        }

        if (freetextInput) {
            freetextInput.addEventListener('input', updateFreetext);
        }

        if (confirmButton) {
            confirmButton.addEventListener('click', function () {
                if (done) {
                    close();
                } else {
                    send();
                }
            });
        }
    }

    function initSmsCode() {
        var overlay = document.querySelector('[data-sms-overlay]');
        if (!overlay) {
            return;
        }

        var titleText = overlay.querySelector('[data-sms-title]');
        var phoneStage = overlay.querySelector('[data-sms-stage="phone"]');
        var codeStage = overlay.querySelector('[data-sms-stage="code"]');
        var phoneInput = overlay.querySelector('#sms-phone');
        var codeInput = overlay.querySelector('#sms-code');
        var phoneError = phoneStage ? phoneStage.querySelector('[data-sms-error]') : null;
        var codeError = codeStage ? codeStage.querySelector('[data-sms-error]') : null;
        var countdown = overlay.querySelector('[data-sms-countdown]');
        var requestButton = overlay.querySelector('[data-sms-request]');
        var verifyButton = overlay.querySelector('[data-sms-verify]');
        var cancelButton = overlay.querySelector('[data-sms-cancel]');
        var backButton = overlay.querySelector('[data-sms-back]');

        var current = null;
        var countdownTimer = null;
        var lastTrigger = null;

        function csrfToken() {
            var meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        }

        function showError(errorElement, message) {
            if (!errorElement) {
                return;
            }
            if (message) {
                errorElement.textContent = message;
                errorElement.hidden = false;
            } else {
                errorElement.textContent = '';
                errorElement.hidden = true;
            }
        }

        function setStage(stage) {
            if (phoneStage) {
                phoneStage.hidden = stage !== 'phone';
            }
            if (codeStage) {
                codeStage.hidden = stage !== 'code';
            }
        }

        function stopCountdown() {
            if (countdownTimer !== null) {
                clearInterval(countdownTimer);
                countdownTimer = null;
            }
        }

        function startCountdown(seconds) {
            stopCountdown();
            var remaining = seconds;
            if (countdown) {
                countdown.textContent = String(remaining);
            }
            countdownTimer = setInterval(function () {
                remaining -= 1;
                if (countdown) {
                    countdown.textContent = String(remaining);
                }
                if (remaining <= 0) {
                    stopCountdown();
                    showError(codeError, 'Der Code ist abgelaufen. Bitte fordern Sie einen neuen Code an.');
                    if (verifyButton) {
                        verifyButton.disabled = true;
                    }
                }
            }, 1000);
        }

        function reset() {
            stopCountdown();
            if (phoneInput) {
                phoneInput.value = '';
            }
            if (codeInput) {
                codeInput.value = '';
            }
            showError(phoneError, '');
            showError(codeError, '');
            if (requestButton) {
                requestButton.disabled = false;
            }
            if (verifyButton) {
                verifyButton.disabled = false;
            }
            setStage('phone');
        }

        function close() {
            stopCountdown();
            overlay.hidden = true;
            document.body.classList.remove('has-announcement-overlay');
            current = null;
            if (lastTrigger) {
                lastTrigger.focus();
                lastTrigger = null;
            }
        }

        function open(trigger) {
            current = {
                id: trigger.getAttribute('data-protected-id'),
                href: trigger.getAttribute('data-nav-href') || '',
                external: trigger.getAttribute('data-nav-external') === '1',
                alarm: trigger.hasAttribute('data-alarm-id'),
                title: trigger.getAttribute('data-nav-title') || '',
                trigger: trigger
            };

            reset();
            if (titleText) {
                titleText.textContent = current.title ? 'Geschützter Zugriff: ' + current.title : 'Geschützter Zugriff';
            }

            lastTrigger = trigger;
            overlay.hidden = false;
            document.body.classList.add('has-announcement-overlay');
            if (phoneInput) {
                phoneInput.focus();
            }
        }

        function navigate() {
            if (!current) {
                return;
            }
            if (current.alarm) {
                var alarmTrigger = current.trigger || document.querySelector('[data-alarm-id="' + current.id + '"]');
                close();
                if (openAlarm && alarmTrigger) {
                    openAlarm(alarmTrigger);
                }
                return;
            }
            if (current.external) {
                window.open(current.href, '_blank', 'noopener,noreferrer');
            } else {
                window.location.href = current.href;
            }
            close();
        }

        function sendCode(event) {
            event.preventDefault();
            if (!current) {
                return;
            }

            var phone = phoneInput ? phoneInput.value.trim() : '';
            if (phone === '') {
                showError(phoneError, 'Bitte eine Rufnummer angeben.');
                return;
            }
            showError(phoneError, '');

            if (requestButton) {
                requestButton.disabled = true;
            }

            var body = new FormData();
            body.append('_token', csrfToken());
            body.append('navigation_id', current.id);
            body.append('phone', phone);

            fetch('/api/sms-code/send', { method: 'POST', body: body })
                .then(function (response) {
                    return response.json().catch(function () { return {}; });
                })
                .then(function (data) {
                    if (requestButton) {
                        requestButton.disabled = false;
                    }

                    if (data && data.status === 'success') {
                        current.phone = phone;
                        if (codeInput) {
                            codeInput.value = '';
                        }
                        showError(codeError, '');
                        setStage('code');
                        if (codeInput) {
                            codeInput.focus();
                        }
                        var timeout = parseInt(data.timeout, 10);
                        startCountdown(isNaN(timeout) ? 120 : timeout);
                    } else {
                        // Der Server gibt bei nicht hinterlegten Rufnummern
                        // bewusst KEINEN Hinweis aus; nur echte Fehler landen hier.
                        showError(phoneError, (data && data.message) || 'Der Code konnte nicht angefordert werden.');
                    }
                })
                .catch(function () {
                    if (requestButton) {
                        requestButton.disabled = false;
                    }
                    showError(phoneError, 'Der Code konnte nicht angefordert werden.');
                });
        }

        function verifyCode(event) {
            event.preventDefault();
            if (!current) {
                return;
            }

            var code = codeInput ? codeInput.value.trim() : '';
            if (code === '') {
                showError(codeError, 'Bitte den Code eingeben.');
                return;
            }
            showError(codeError, '');

            if (verifyButton) {
                verifyButton.disabled = true;
            }

            var body = new FormData();
            body.append('_token', csrfToken());
            body.append('navigation_id', current.id);
            body.append('phone', current.phone || (phoneInput ? phoneInput.value : ''));
            body.append('code', code);

            fetch('/api/sms-code/verify', { method: 'POST', body: body })
                .then(function (response) {
                    return response.json().catch(function () { return {}; });
                })
                .then(function (data) {
                    if (data && data.status === 'success') {
                        navigate();
                    } else {
                        if (verifyButton) {
                            verifyButton.disabled = false;
                        }
                        showError(codeError, (data && data.message) || 'Der Code ist ungültig.');
                    }
                })
                .catch(function () {
                    if (verifyButton) {
                        verifyButton.disabled = false;
                    }
                    showError(codeError, 'Die Prüfung konnte nicht durchgeführt werden.');
                });
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-protected-id]');
            if (trigger) {
                event.preventDefault();
                open(trigger);
            }
        });

        if (phoneStage) {
            phoneStage.addEventListener('submit', sendCode);
        }
        if (codeStage) {
            codeStage.addEventListener('submit', verifyCode);
        }
        if (cancelButton) {
            cancelButton.addEventListener('click', close);
        }
        if (backButton) {
            backButton.addEventListener('click', function () {
                stopCountdown();
                showError(codeError, '');
                if (verifyButton) {
                    verifyButton.disabled = false;
                }
                setStage('phone');
                if (phoneInput) {
                    phoneInput.focus();
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        initToggles();
        initTracking();
        initAlarms();
        initSmsCode();
    });
})();
