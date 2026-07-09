# Skin/Theme Gallery Extraction

Skin and color-theme management (upload, designer, ratings, forking, built-in
skin browsing) has moved from this repo into `audiobook-librarian-www`, which
is now the real source of truth for this data. This repo's `/api/v1/skins/*`
and `/api/v1/themes/*` endpoints keep working for existing clients, but
internally proxy to www during the transition.

## How it works

- **Reads** (`index`, `show`, `download`, `customizations`) are forwarded to
  www anonymously via `GalleryProxyClient::get()`. www's read endpoints are
  public and rate-limited on its own side — no identity needs to cross the
  boundary.
- **Writes** (`upload`, `update`, `destroy`, `fork`, `rate`, `mySkins`/
  `myThemes`) are forwarded with a signed `X-Gallery-Trust` header identifying
  the already-authenticated server user (`GalleryProxyClient::postAsUser()`
  etc.), verified by `App\Auth\GalleryTrustAuthenticator` in www. This lets
  existing users keep working with zero re-registration, since www never
  sees the mobile client's original token — it only trusts server's signed
  claim about who's acting.
- The trust mechanism is **transitional and swappable** — it's registered as
  its own `gallery_trust` auth guard in www (`config/auth.php`), layered
  alongside (never replacing) www's own Sanctum/session auth. It can be
  deleted wholesale, on the www side only, once www has independent
  end-user auth for its own clients.
- The Blade UI (skin/theme gallery pages, designer, admin panels, built-in
  skin browsing) has been deleted from this repo entirely. The old routes
  (same names, e.g. `gallery.skins.show`) now redirect to the identical path
  on www — see the `$redirectToGalleryWww` closure in `routes/web.php`.

## Configuration

Set these in `.env` (see `.env.example` for the full block):

- `GALLERY_WWW_BASE_URL` — base URL of the running www instance.
- `GALLERY_PROXY_SHARED_SECRET` — HMAC secret for the trust header. Must
  match `GALLERY_PROXY_SHARED_SECRET` in www's `.env` **exactly**. Never
  commit this value.

## One-time data migration

`php artisan gallery:migrate-to-www [--dry-run]` copies existing skins,
themes, ratings, and customizations (and the users who own them) from this
app's database into www's. It's read-only against this app — all writes go
to the `www` DB connection and `WWW_STORAGE_PATH`. Safe to re-run (users are
matched by email, content is matched by source ID). See the command's
docblock in `app/Console/Commands/MigrateGalleryToWww.php` for the exact
file-resolution and ID-remapping logic.

Required `.env` config for the migration command only (not needed for the
ongoing proxy): `WWW_DB_CONNECTION`, `WWW_DB_DATABASE`, `WWW_DB_HOST`,
`WWW_DB_PORT`, `WWW_DB_USERNAME`, `WWW_DB_PASSWORD`, `WWW_STORAGE_PATH`.

## Failure modes

If www is unreachable or times out, `GalleryProxyClient` translates the
connection failure into `App\Exceptions\GalleryProxyUnavailableException`,
rendered as a clean `502` (see `bootstrap/app.php`) rather than a raw 500.
