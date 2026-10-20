/* Administration der Help-Desk-KI: Modell ermitteln und Verbindung testen */
(function () {
    "use strict";

    var form = document.querySelector("[data-ai-form]");
    if (!form) { return; }

    var ui = window.AppUI;
    var resultBox = document.querySelector("[data-ai-result]");
    var stepsBox = document.querySelector("[data-ai-steps]");
    var questionBox = document.querySelector("[data-ai-question]");
    var answerBox = document.querySelector("[data-ai-answer]");
    var metaBox = document.querySelector("[data-ai-meta]");
    var modelField = document.getElementById("ai-model");
    var modelList = document.getElementById("ai-models");
    var promptField = document.getElementById("ai-test-prompt");

    function field(name) {
        return form.querySelector('[name="' + name + '"]');
    }

    function value(name) {
        var el = field(name);
        if (!el) { return ""; }
        if (el.type === "checkbox") { return el.checked ? "1" : ""; }
        return el.value.trim();
    }

    function payload() {
        return {
            enabled: value("enabled"),
            name: value("name"),
            url: value("url"),
            model: value("model"),
            timeout: value("timeout"),
            api_key: value("api_key"),
            test_prompt: promptField ? promptField.value.trim() : ""
        };
    }

    function messageOf(error) {
        if (error && error.data) {
            if (error.data.message) { return error.data.message; }
            if (error.data.error) { return error.data.error; }
            if (error.data.errors) {
                var first = Object.keys(error.data.errors)[0];
                if (first) { return error.data.errors[first]; }
            }
        }
        return (error && error.message) || "Unbekannter Fehler";
    }

    // ---------------------------------------------------------------- Modell ermitteln

    function applyModels(models, selected) {
        if (modelList) {
            modelList.innerHTML = "";
            models.forEach(function (model) {
                var option = document.createElement("option");
                option.value = model;
                modelList.appendChild(option);
            });
        }
        if (!modelField || models.length === 0) { return; }

        var current = modelField.value.trim();
        var picker = document.querySelector("[data-ai-model-picker]");
        if (models.length === 1) {
            modelField.value = models[0];
            if (picker) { picker.remove(); }
            return;
        }

        if (!picker) {
            picker = document.createElement("select");
            picker.setAttribute("data-ai-model-picker", "");
            picker.className = "ai-model-picker";
            picker.setAttribute("aria-label", "Gefundene Modelle");
            picker.addEventListener("change", function () {
                modelField.value = picker.value;
            });
            var row = modelField.closest(".ai-model-field") || modelField;
            row.parentNode.insertBefore(picker, row.nextSibling);
        }
        picker.innerHTML = "";
        models.forEach(function (model) {
            var option = document.createElement("option");
            option.value = model;
            option.textContent = model;
            picker.appendChild(option);
        });
        var chosen = models.indexOf(current) >= 0 ? current : (models.indexOf(selected) >= 0 ? selected : models[0]);
        picker.value = chosen;
        modelField.value = chosen;
    }

    var modelsButton = document.querySelector("[data-ai-models]");
    if (modelsButton) {
        modelsButton.addEventListener("click", async function () {
            modelsButton.disabled = true;
            try {
                var data = await ui.api("/admin/helpdesk-ai/models", {
                    method: "POST",
                    body: { url: value("url"), api_key: value("api_key") }
                });
                applyModels(data.models || [], data.selected || "");
                ui.toast(data.message || "Modelle ermittelt.", "success");
            } catch (error) {
                ui.toast(messageOf(error), "error");
            } finally {
                modelsButton.disabled = false;
            }
        });
    }

    // ---------------------------------------------------------------- Verbindung testen

    function showResult(data) {
        if (!resultBox) { return; }
        resultBox.hidden = false;

        if (stepsBox) {
            stepsBox.innerHTML = "";
            (data.steps || []).forEach(function (step) {
                var item = document.createElement("li");
                item.className = "ai-step " + (step.ok ? "is-ok" : "is-fail");
                var label = document.createElement("strong");
                label.textContent = step.label + ": ";
                var text = document.createElement("span");
                text.textContent = step.message;
                item.appendChild(label);
                item.appendChild(text);
                stepsBox.appendChild(item);
            });
        }

        if (questionBox) {
            questionBox.hidden = !data.prompt;
            questionBox.textContent = data.prompt || "";
        }
        if (answerBox) {
            answerBox.hidden = !data.answer;
            answerBox.textContent = data.answer || "";
        }
        if (metaBox) {
            var parts = [];
            if (data.model) { parts.push("Modell: " + data.model); }
            if (data.duration_ms) { parts.push(data.duration_ms + " ms"); }
            if (data.tokens) { parts.push(data.tokens + " Token"); }
            var errors = data.errors ? Object.keys(data.errors) : [];
            errors.forEach(function (key) { parts.push(data.errors[key]); });
            metaBox.textContent = parts.join(" · ");
            metaBox.hidden = parts.length === 0;
            metaBox.classList.toggle("text-danger", errors.length > 0 || !data.ok);
        }
    }

    var testButton = document.querySelector("[data-ai-test]");
    if (testButton) {
        testButton.addEventListener("click", async function () {
            testButton.disabled = true;
            try {
                var data = await ui.api("/admin/helpdesk-ai/test", { method: "POST", body: payload() });
                showResult(data);
                ui.toast(data.message || (data.ok ? "Test erfolgreich." : "Test fehlgeschlagen."), data.ok ? "success" : "error");
            } catch (error) {
                var data2 = (error && error.data) || {};
                showResult({
                    ok: false,
                    steps: data2.steps || [],
                    prompt: data2.prompt || (promptField ? promptField.value.trim() : ""),
                    answer: data2.answer || "",
                    model: data2.model || "",
                    errors: data2.errors || {},
                    duration_ms: 0,
                    tokens: null
                });
                ui.toast(messageOf(error), "error");
            } finally {
                testButton.disabled = false;
            }
        });
    }
})();
