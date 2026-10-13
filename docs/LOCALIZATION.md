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

Converted so far: the shared layout (navbar, app-connect modal and QR component), the login/magic-link/password-reset pages, the storage-unavailable page and its message. Most other Blade views, emails, JavaScript and controller flash messages still contain hard-coded English; wrap them in `__()` / `t()` as you touch them. Library content (genres, tags, titles, descriptions) is data and is not translated by these files.
