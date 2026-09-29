# Import drafts API (`imports.v1`)

The Kotlin desktop and terminal importers (`audiobook-librarian-importer`) use this API. The
full rebuild plan and contract live in that repository under `docs/IMPORTER_REBUILD_PLAN.md`.

## Enabling

1. Run migrations (additive only).
2. Set `IMPORT_DRAFTS_ENABLED=true`.
3. Admins can import automatically. Give anyone else the **Import Books** permission from the
   admin user page.

`GET /api/v1/imports/capabilities` tells a client whether drafts are enabled for the signed-in
user and which limits apply.

## Implemented (phase 1)

| Route | Purpose |
|---|---|
| `GET /imports/capabilities` | Feature flag, transfer modes and limits |
| `POST /imports/drafts` | Create a draft from a `SourceObservation` (requires `Idempotency-Key`) |
| `GET /imports/drafts` | Caller's drafts, newest first, `?state=` and `?cursor=` |
| `GET /imports/drafts/{draftId}` | One draft; `ETag` is the quoted revision, `If-None-Match` returns 304 |
| `POST /imports/drafts/{draftId}/cancel` | Cancel; optional `If-Match` guards against stale revisions |

Drafts stay in `created` until interpretation (phase 2) is implemented. No audio bytes are
accepted yet.

## Rules

- Paths are relative, POSIX, NFC-normalized and unique case-insensitively. Absolute, drive-letter,
  backslash, `.`/`..`/empty-segment and control-character paths are rejected with 422.
- Errors: `{ "contract_version": "imports.v1", "error": { "code", "message", "details", "retryable" } }`.
- Retrying a POST with the same `Idempotency-Key` and body replays the stored response
  (`Idempotent-Replayed: true`); a different body with the same key is `422 idempotency_key_reused`.
- Only the owner (or an admin) can read or cancel a draft; others get 404.
