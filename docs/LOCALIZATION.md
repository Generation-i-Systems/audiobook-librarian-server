# Localization

The plumbing for translating the web UI is in place; no language beyond English is enabled yet.

## How the locale is chosen

`App\Http\Middleware\SetLocale` (web and API groups) applies the first match from:

1. the signed-in user's saved `users.locale` (web sessions; API requests authenticate later, so they use the header),
2. the session choice made through `POST /locale`,
3. the best `Accept-Language` match,
4. `config('locales.default')`.

Only codes listed in `config/locales.php` are ever applied or accepted (`POST /locale`, `PUT /user`).

## Adding a language

1. Add `'es' => 'Español'` to `supported` in `config/locales.php`.
2. Create `lang/es.json` mapping the **English text** to its translation (keys are the English source strings, as with `__('Save')`). Strings missing from the file fall back to English.
3. Translate the framework messages in `lang/es/` (copy `lang/en/` — `validation.php`, `auth.php`, `passwords.php`, `pagination.php`).
4. Show a switcher that `POST`s `locale` to `/locale` (not built yet; hide it while only one language is supported).

## Writing translatable text

- Blade/PHP: `{{ __('Save') }}`, `{{ __('Hello :name', ['name' => $user->name]) }}`.
- JavaScript: `t('Save')`, `t('Hello :name', { name })` (`resources/js/i18n.js`, also `window.__`). The layout emits the current locale's `lang/*.json` as `window.APP_I18N`.
- Do not translate API error messages: clients should branch on the `code`/status, not the text.

## Status

Spanish (`es`) is enabled: `lang/es.json` (app strings) and `lang/es/*.php` (framework: auth, pagination, passwords, validation). The navbar shows a language selector when more than one locale is supported.

Converted: the layout, QR component and every Blade view except those listed below, plus controller flash messages (`->with('success'|'error'|..., __(...))`) and static `confirm()`/`alert()` strings in view scripts (via `window.__`).

Still English: API docs pages (`resources/views/docs`), emails (`resources/views/emails` — need `Mail::to()->locale()`), the Privacy Policy and Terms (legal text needs a human/legal translation), sentences assembled from fragments around `{{ }}` expressions, JavaScript under `resources/js`, API JSON messages, and validation `attributes` beyond a few common fields. Library content (genres, tags, titles, descriptions) is data and is not translated.

`tests/Unit/Localization/SpanishCatalogTest` fails when a literal `__('...')` in a view has no `lang/es.json` entry or a placeholder differs; add the Spanish text when you add a string.
