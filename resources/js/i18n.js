// UI strings for JavaScript. The server emits window.APP_I18N (see layouts/app.blade.php)
// as { "English text": "translated text" }; untranslated keys fall back to the English text,
// exactly like PHP's __(). Placeholders use the same ":name" syntax as Laravel.
export function t(key, replacements = {}) {
    const dictionary = window.APP_I18N || {};
    let text = Object.prototype.hasOwnProperty.call(dictionary, key) ? dictionary[key] : key;

    Object.keys(replacements)
        .sort((a, b) => b.length - a.length)
        .forEach((name) => {
            text = text.split(":" + name).join(String(replacements[name]));
        });

    return text;
}

window.__ = t;
