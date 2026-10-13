// @jest-environment jsdom
import { t } from "../../resources/js/i18n";

describe("t()", () => {
    afterEach(() => {
        delete window.APP_I18N;
    });

    test.each([
        ["translated key", { Save: "Guardar" }, "Save", {}, "Guardar"],
        ["missing key falls back to the key", { Save: "Guardar" }, "Cancel", {}, "Cancel"],
        ["no dictionary at all", undefined, "Cancel", {}, "Cancel"],
        ["named replacement", { "Hello :name": "Hola :name" }, "Hello :name", { name: "Ana" }, "Hola Ana"],
        ["replacement also applies to untranslated keys", {}, "Hello :name", { name: "Ana" }, "Hello Ana"],
        ["longer names win over their prefixes", {}, ":a :ab", { a: "1", ab: "2" }, "1 2"],
        ["inherited keys are not translations", {}, "constructor", {}, "constructor"],
    ])("%s", (_label, dictionary, key, replacements, expected) => {
        window.APP_I18N = dictionary;
        expect(t(key, replacements)).toBe(expected);
    });
});
