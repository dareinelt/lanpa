(function () {
    "use strict";

    const wizard = document.querySelector("[data-hd-wizard]");
    if (!wizard) { return; }

    const form = wizard.closest("form");
    if (!form) { return; }

    const sections = Array.prototype.slice.call(wizard.querySelectorAll("[data-hd-step]"));
    const total = sections.length;
    if (total === 0) { return; }

    const progressBar = wizard.querySelector("[data-hd-wizard-bar]");
    const positionLabel = wizard.querySelector("[data-hd-wizard-position]");
    const titleLabel = wizard.querySelector("[data-hd-wizard-title]");
    const prevButton = wizard.querySelector("[data-hd-wizard-prev]");
    const nextButton = wizard.querySelector("[data-hd-wizard-next]");
    const submitButton = wizard.querySelector("[data-hd-wizard-submit]");
    const stepField = form.querySelector("[data-hd-wizard-step]");

    const byIndex = new Map();
    sections.forEach(function (sectionEl) {
        byIndex.set(parseInt(sectionEl.getAttribute("data-hd-step"), 10), sectionEl);
    });

    const requestedStart = parseInt(wizard.getAttribute("data-hd-wizard-start"), 10) || 1;
    let current = Math.max(1, Math.min(total, requestedStart));
    let maxVisited = current;
    const expanded = new Set();
    let allowLeave = false;
    let submitting = false;

    function section(index) {
        return byIndex.get(index) || null;
    }

    function body(index) {
        const target = section(index);
        return target ? target.querySelector("[data-hd-step-body]") : null;
    }

    function shorten(value, length) {
        const text = String(value || "").replace(/\s+/g, " ").trim();
        if (text.length <= length) { return text; }
        return text.slice(0, length - 1).replace(/\s+$/, "") + "…";
    }

    function fieldValue(root, name) {
        const field = root.querySelector("[name='" + name + "']");
        return field ? String(field.value || "").trim() : "";
    }

    function selectedText(root, name) {
        const select = root.querySelector("[name='" + name + "']");
        if (!select || !select.value || select.selectedIndex < 0) { return ""; }
        const option = select.options[select.selectedIndex];
        return option ? String(option.textContent || "").trim() : "";
    }

    function pickerName(root, name) {
        const hidden = root.querySelector("input[name='" + name + "']");
        if (!hidden || !hidden.value) { return ""; }
        const picker = hidden.closest("[data-picker]");
        const label = picker ? picker.querySelector("[data-picker-label]") : null;
        return label ? String(label.textContent || "").trim() : "";
    }

    function join(parts) {
        return parts.filter(function (part) { return part; }).join(" · ");
    }

    const summaries = {
        1: function (root) {
            return shorten(fieldValue(root, "subject"), 70);
        },
        2: function (root) {
            const requester = pickerName(root, "requester_employee_id");
            const affected = pickerName(root, "affected_employee_id");
            return join([
                requester ? "Melder: " + requester : "",
                affected ? "Betroffener: " + affected : ""
            ]);
        },
        3: function (root) {
            return shorten(fieldValue(root, "description"), 90);
        },
        4: function (root) {
            const type = selectedText(root, "ticket_type_id");
            const category = selectedText(root, "category_id");
            const subcategory = selectedText(root, "subcategory_id");
            return join([
                type ? "Typ: " + type : "",
                category ? "Kategorie: " + category : "",
                subcategory ? "Unterkategorie: " + subcategory : ""
            ]);
        },
        5: function (root) {
            const impact = selectedText(root, "impact");
            const urgency = selectedText(root, "urgency");
            const priority = selectedText(root, "priority_id") || "automatisch";
            return join([
                impact ? "Auswirkung: " + impact : "",
                urgency ? "Dringlichkeit: " + urgency : "",
                "Priorität: " + priority
            ]);
        },
        6: function (root) {
            const location = selectedText(root, "location_id");
            const costCenter = selectedText(root, "cost_center_id");
            return join([
                location ? "Standort: " + location : "",
                costCenter ? "Kostenstelle: " + costCenter : ""
            ]);
        },
        7: function (root) {
            const group = selectedText(root, "group_id");
            const assignee = selectedText(root, "assignee_user_id") || "nicht zugewiesen";
            const deputy = selectedText(root, "deputy_user_id");
            return join([
                group ? "Gruppe: " + group : "",
                "Bearbeiter: " + assignee,
                deputy ? "Vertretung: " + deputy : ""
            ]);
        },
        8: function (root) {
            const assets = root.querySelector("[name='asset_ids[]']");
            const count = assets ? assets.selectedOptions.length : 0;
            const tags = fieldValue(root, "tags");
            return join([
                count > 0 ? count + (count === 1 ? " Asset" : " Assets") : "",
                tags ? "Tags: " + shorten(tags, 50) : ""
            ]);
        },
        9: function (root) {
            const confirm = root.querySelector("input[name='confirm_send']");
            return confirm && confirm.checked ? "Bestätigung wird gesendet" : "Keine Bestätigung";
        }
    };

    function refreshSummaries() {
        sections.forEach(function (sectionEl) {
            const index = parseInt(sectionEl.getAttribute("data-hd-step"), 10);
            const summary = sectionEl.querySelector("[data-hd-step-summary]");
            if (!summary) { return; }
            const build = summaries[index];
            summary.textContent = index < current && build ? build(sectionEl) : "";
        });
    }

    function render() {
        sections.forEach(function (sectionEl) {
            const index = parseInt(sectionEl.getAttribute("data-hd-step"), 10);
            const isCurrent = index === current;
            const isDone = index < current;
            const isFuture = index > current;
            const isExpanded = expanded.has(index);

            sectionEl.classList.toggle("is-current", isCurrent);
            sectionEl.classList.toggle("is-done", isDone);
            sectionEl.classList.toggle("is-future", isFuture);
            sectionEl.classList.toggle("is-expanded", isExpanded);
            if (isCurrent) {
                sectionEl.setAttribute("aria-current", "step");
            } else {
                sectionEl.removeAttribute("aria-current");
            }

            const goto = sectionEl.querySelector("[data-hd-step-goto]");
            if (goto) {
                goto.disabled = isFuture || isCurrent;
            }

            const toggle = sectionEl.querySelector("[data-hd-step-toggle]");
            if (toggle) {
                toggle.hidden = !isDone;
                toggle.disabled = !isDone;
                toggle.setAttribute("aria-expanded", isExpanded ? "true" : "false");
                toggle.setAttribute("aria-label", isExpanded ? "Zusammenfassung ausblenden" : "Zusammenfassung einblenden");
            }

            const stepBody = sectionEl.querySelector("[data-hd-step-body]");
            if (stepBody) {
                stepBody.hidden = !(isCurrent || isExpanded);
            }
        });

        if (progressBar) {
            progressBar.max = total;
            progressBar.value = current;
        }
        if (positionLabel) {
            positionLabel.textContent = "Schritt " + current + " von " + total;
        }
        if (titleLabel) {
            const currentSection = section(current);
            titleLabel.textContent = currentSection ? currentSection.getAttribute("data-hd-step-title") || "" : "";
        }
        if (prevButton) { prevButton.hidden = current === 1; }
        if (nextButton) { nextButton.hidden = current === total; }
        if (submitButton) { submitButton.hidden = current !== total; }
        if (stepField) { stepField.value = String(current); }
        refreshSummaries();
    }

    function focusCurrent() {
        const target = body(current);
        if (!target) { return; }
        const field = target.querySelector("input:not([type='hidden']):not([disabled]), select:not([disabled]), textarea:not([disabled])");
        if (field) {
            try { field.focus({ preventScroll: true }); } catch (e) { field.focus(); }
        }
        const currentSection = section(current);
        if (currentSection && typeof currentSection.scrollIntoView === "function") {
            currentSection.scrollIntoView({ block: "nearest" });
        }
    }

    function clearFieldError(field) {
        if (!field || !field.closest) { return; }
        const group = field.closest(".form-group");
        if (group) { group.classList.remove("has-error"); }
    }

    function invalidField(index) {
        const target = body(index);
        if (!target) { return null; }
        const fields = target.querySelectorAll("input, select, textarea");
        for (let i = 0; i < fields.length; i += 1) {
            const field = fields[i];
            if (field.disabled || field.type === "hidden") { continue; }
            if (!field.checkValidity()) { return field; }
        }
        return null;
    }

    function validate(index) {
        const field = invalidField(index);
        if (!field) { return true; }
        const group = field.closest(".form-group");
        if (group) { group.classList.add("has-error"); }
        if (typeof field.reportValidity === "function") { field.reportValidity(); }
        field.focus();
        return false;
    }

    function showStep(index, focus) {
        current = Math.max(1, Math.min(total, index));
        maxVisited = Math.max(maxVisited, current);
        expanded.clear();
        render();
        if (focus !== false) { focusCurrent(); }
    }

    function goNext() {
        if (!validate(current)) { return; }
        if (current >= total) { return; }
        showStep(current + 1, true);
    }

    function goPrev() {
        if (current <= 1) { return; }
        showStep(current - 1, true);
    }

    function goTo(index) {
        if (index === current || index < 1 || index > total) { return; }
        if (index > maxVisited) { return; }
        if (index > current && !validate(current)) { return; }
        showStep(index, true);
    }

    sections.forEach(function (sectionEl) {
        const index = parseInt(sectionEl.getAttribute("data-hd-step"), 10);
        const goto = sectionEl.querySelector("[data-hd-step-goto]");
        if (goto) {
            goto.addEventListener("click", function () { goTo(index); });
        }
        const toggle = sectionEl.querySelector("[data-hd-step-toggle]");
        if (toggle) {
            toggle.addEventListener("click", function () {
                if (index >= current) { return; }
                if (expanded.has(index)) {
                    expanded.delete(index);
                } else {
                    expanded.add(index);
                }
                render();
            });
        }
    });

    if (prevButton) { prevButton.addEventListener("click", goPrev); }
    if (nextButton) { nextButton.addEventListener("click", goNext); }

    form.addEventListener("keydown", function (event) {
        if (event.key !== "Enter" || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) { return; }
        const target = event.target;
        if (!target || target.tagName === "TEXTAREA" || target.tagName === "BUTTON") { return; }
        if (target.type === "submit") { return; }
        if (current < total) {
            event.preventDefault();
            goNext();
            return;
        }
        event.preventDefault();
        if (submitButton) { submitButton.click(); }
    });

    form.addEventListener("input", function (event) {
        clearFieldError(event.target);
        refreshSummaries();
    });
    form.addEventListener("change", function (event) {
        clearFieldError(event.target);
        refreshSummaries();
    });
    form.addEventListener("picker:change", refreshSummaries);
    form.addEventListener("hd:options", refreshSummaries);

    form.addEventListener("submit", function (event) {
        for (let index = 1; index <= total; index += 1) {
            if (invalidField(index)) {
                event.preventDefault();
                showStep(index, false);
                validate(index);
                return;
            }
        }
        submitting = true;
    });

    function snapshot() {
        const data = new FormData(form);
        const parts = [];
        data.forEach(function (value, key) {
            // Der Schritt-Zähler ändert sich bei jeder Navigation und zählt nicht als Bearbeitung.
            if (key === "wizard_step") { return; }
            parts.push(key + "=" + value);
        });
        return parts.join("\u0000");
    }

    const initialSnapshot = snapshot();

    function isDirty() {
        return snapshot() !== initialSnapshot;
    }

    function confirmLeave() {
        if (submitting || !isDirty()) { return true; }
        return window.confirm("Der Assistent wird geschlossen. Der bisherige Bearbeitungsfortschritt geht verloren. Wirklich schließen?");
    }

    document.querySelectorAll("[data-hd-confirm-leave], [data-hd-wizard-close]").forEach(function (link) {
        link.addEventListener("click", function (event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
            if (!confirmLeave()) {
                event.preventDefault();
                return;
            }
            allowLeave = true;
        });
    });

    window.addEventListener("beforeunload", function (event) {
        if (allowLeave || submitting || !isDirty()) { return; }
        event.preventDefault();
        event.returnValue = "";
        return "";
    });

    render();
}());
