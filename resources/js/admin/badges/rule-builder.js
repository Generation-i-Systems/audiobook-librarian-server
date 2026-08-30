/**
 * Admin badge rule builder: renders/edits the version-2 AND/OR condition-group JSON
 * (see App\Models\Badge::evaluateCriteria) as a nested tree of rows, and serializes it
 * back into the hidden "criteria" textarea on submit.
 */
(function () {
    "use strict";

    function legacyToGroup(criteria) {
        if (criteria && Array.isArray(criteria.conditions)) {
            return criteria;
        }

        const conditions = [];
        if (criteria && typeof criteria === "object") {
            Object.keys(criteria).forEach((stat) => {
                const requirement = criteria[stat];
                if (requirement && typeof requirement === "object" && !Array.isArray(requirement)) {
                    conditions.push({
                        stat: stat,
                        operator: "between",
                        value: [requirement.min ?? 0, requirement.max ?? requirement.min ?? 0],
                    });
                } else {
                    conditions.push({ stat: stat, operator: ">=", value: requirement });
                }
            });
        }

        return { version: 2, logic: "AND", conditions: conditions };
    }

    function createEl(tag, className, text) {
        const el = document.createElement(tag);
        if (className) {
            el.className = className;
        }
        if (text !== undefined) {
            el.textContent = text;
        }
        return el;
    }

    function initRuleBuilder(root) {
        const criteriaTypes = JSON.parse(root.dataset.criteriaTypes || "{}");
        const operators = JSON.parse(root.dataset.operators || "{}");
        const initial = legacyToGroup(JSON.parse(root.dataset.initialCriteria || "null"));
        const output = document.getElementById(root.dataset.outputField);
        const container = root.querySelector(".rule-builder-tree");

        function renderCondition(condition, onRemove) {
            const row = createEl("div", "rule-condition d-flex gap-2 align-items-start mb-2 flex-wrap");

            const statSelect = createEl("select", "form-select form-select-sm w-auto");
            Object.keys(criteriaTypes).forEach((key) => {
                const opt = createEl("option", null, criteriaTypes[key]);
                opt.value = key;
                if (condition.stat === key) {
                    opt.selected = true;
                }
                statSelect.appendChild(opt);
            });

            const operatorSelect = createEl("select", "form-select form-select-sm w-auto");
            Object.keys(operators).forEach((key) => {
                const opt = createEl("option", null, operators[key]);
                opt.value = key;
                if (condition.operator === key) {
                    opt.selected = true;
                }
                operatorSelect.appendChild(opt);
            });

            const valueWrap = createEl("span", "d-flex gap-1");
            const minInput = createEl("input", "form-control form-control-sm");
            minInput.type = "number";
            minInput.style.width = "7rem";
            const maxInput = createEl("input", "form-control form-control-sm");
            maxInput.type = "number";
            maxInput.style.width = "7rem";
            maxInput.placeholder = "max";

            function syncValueInputs() {
                const isBetween = operatorSelect.value === "between";
                maxInput.hidden = !isBetween;
                if (isBetween) {
                    const range = Array.isArray(condition.value) ? condition.value : [0, 0];
                    minInput.value = range[0] ?? 0;
                    maxInput.value = range[1] ?? 0;
                    minInput.placeholder = "min";
                } else {
                    minInput.value = Array.isArray(condition.value) ? "" : (condition.value ?? "");
                    minInput.placeholder = "value";
                }
            }

            operatorSelect.addEventListener("change", () => {
                condition.operator = operatorSelect.value;
                syncValueInputs();
            });
            statSelect.addEventListener("change", () => {
                condition.stat = statSelect.value;
            });

            valueWrap.appendChild(minInput);
            valueWrap.appendChild(maxInput);
            syncValueInputs();

            const removeBtn = createEl("button", "btn btn-sm btn-outline-danger", "Remove");
            removeBtn.type = "button";
            removeBtn.addEventListener("click", onRemove);

            row.append(statSelect, operatorSelect, valueWrap, removeBtn);

            row.readValue = function () {
                condition.stat = statSelect.value;
                condition.operator = operatorSelect.value;
                if (operatorSelect.value === "between") {
                    condition.value = [Number(minInput.value) || 0, Number(maxInput.value) || 0];
                } else {
                    condition.value = Number(minInput.value) || 0;
                }
                return condition;
            };

            return row;
        }

        function renderGroup(group, onRemoveSelf) {
            const wrap = createEl("div", "rule-group border rounded p-2 mb-2");

            const header = createEl("div", "d-flex align-items-center gap-2 mb-2");
            const logicSelect = createEl("select", "form-select form-select-sm w-auto");
            ["AND", "OR"].forEach((logic) => {
                const opt = createEl("option", null, "Match " + logic);
                opt.value = logic;
                if ((group.logic || "AND").toUpperCase() === logic) {
                    opt.selected = true;
                }
                logicSelect.appendChild(opt);
            });
            logicSelect.addEventListener("change", () => {
                group.logic = logicSelect.value;
            });
            header.appendChild(logicSelect);

            if (onRemoveSelf) {
                const removeGroupBtn = createEl("button", "btn btn-sm btn-outline-danger", "Remove group");
                removeGroupBtn.type = "button";
                removeGroupBtn.addEventListener("click", onRemoveSelf);
                header.appendChild(removeGroupBtn);
            }

            wrap.appendChild(header);

            const childList = createEl("div", "rule-group-children ps-3");
            wrap.appendChild(childList);

            const rowEls = [];

            function addChildEl(node, index) {
                let el;
                if (node && Array.isArray(node.conditions)) {
                    el = renderGroup(node, () => {
                        group.conditions.splice(group.conditions.indexOf(node), 1);
                        el.remove();
                        rowEls.splice(rowEls.indexOf(el), 1);
                    });
                } else {
                    el = renderCondition(node, () => {
                        group.conditions.splice(group.conditions.indexOf(node), 1);
                        el.remove();
                        rowEls.splice(rowEls.indexOf(el), 1);
                    });
                }
                childList.appendChild(el);
                rowEls.push(el);
            }

            (group.conditions || []).forEach(addChildEl);

            const actions = createEl("div", "d-flex gap-2");
            const addConditionBtn = createEl("button", "btn btn-sm btn-outline-secondary", "+ Condition");
            addConditionBtn.type = "button";
            addConditionBtn.addEventListener("click", () => {
                const condition = {
                    stat: Object.keys(criteriaTypes)[0] || "",
                    operator: ">=",
                    value: 0,
                };
                group.conditions.push(condition);
                addChildEl(condition);
            });

            const addGroupBtn = createEl("button", "btn btn-sm btn-outline-secondary", "+ Group");
            addGroupBtn.type = "button";
            addGroupBtn.addEventListener("click", () => {
                const child = { logic: "AND", conditions: [] };
                group.conditions.push(child);
                addChildEl(child);
            });

            actions.append(addConditionBtn, addGroupBtn);
            wrap.appendChild(actions);

            wrap.readValue = function () {
                group.logic = logicSelect.value;
                group.conditions = rowEls.map((el) => el.readValue());
                return group;
            };

            return wrap;
        }

        const rootGroupEl = renderGroup(initial, null);
        container.appendChild(rootGroupEl);

        const form = root.closest("form");
        if (form) {
            form.addEventListener("submit", () => {
                const value = rootGroupEl.readValue();
                output.value = JSON.stringify({ version: 2, logic: value.logic, conditions: value.conditions });
            });
        }
    }

    document.addEventListener("DOMContentLoaded", () => {
        document.querySelectorAll("[data-rule-builder]").forEach(initRuleBuilder);
    });
})();
