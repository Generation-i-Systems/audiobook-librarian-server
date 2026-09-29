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

## Implemented routes

| Route | Purpose |
|---|---|
| `GET /imports/capabilities` | Feature flag, transfer modes and limits |
| `POST /imports/drafts` | Create a draft from a `SourceObservation` (requires `Idempotency-Key`) |
| `GET /imports/drafts` | Caller's drafts, newest first, `?state=` and `?cursor=` |
| `GET /imports/drafts/{draftId}` | One draft; `ETag` is the quoted revision, `If-None-Match` returns 304 |
| `POST /imports/drafts/{draftId}/cancel` | Cancel; optional `If-Match` guards against stale revisions |
| `GET /imports/drafts/{draftId}/events` | Event log for polling, `?after=<cursor>` (phase 2) |

No audio bytes are accepted yet.

## Interpretation (phase 2)

Creating a draft queues `InterpretImportDraftJob` (needs a running queue worker; set
`IMPORT_DRAFT_INTERPRETATION_QUEUE` to use a dedicated queue). States move
`created` -> `interpreting` -> `awaiting_review`. A draft with no audio files goes to
`needs_attention` (`interpretation_error.code = no_audio_files`); an unexpected error goes to
`failed` (`interpretation_failed`). Each transition bumps the revision once and records a
`state_changed` event.

The interpreter reuses the `book:import` helpers so both paths agree:

| Evidence | Helper | Provenance `source_id` / `source` |
|---|---|---|
| Raw tags of the first tagged audio file | `extractMetadataFromFileTags` | `embedded_tag` / "file tags" |
| Inline NFO text (role `nfo`) | `parseNfoContent` | `nfo` / "NFO" |
| Single root audio file name, else the display name | `parseFilenameForMetadata` | `filename` / "file name" or "folder name" |
| Genre of an existing book in the same series | `lookupGenreFromExistingSeries` | `library_series` |
| Online lookup (opt-in, see below) | `BookEnrichmentService::enrichWithExternalData` | `external_enrichment` / provider name |

Sources are merged fill-missing in that order, then normalized with `postProcessAIResult`
(author clean-up, genre mapping, series/title clean-up). When normalization changes the winning
value, a `server_policy` ("library rules") entry leads that field's provenance list.

Online enrichment runs only when `IMPORT_DRAFTS_ENRICHMENT_ENABLED=true` **and** the observation
requested `external_enrichment`. Failures add an `enrichment_unavailable` warning; they never fail
the draft.

### Recommendation shape

```json
{
  "metadata": {"title": "Dust Road", "authors": ["Jane Author"], "narrators": [], "series": null,
               "genres": ["Fantasy"], "tags": [], "language": null, "description": null,
               "cover_artifact_id": null},
  "field_provenance": {"title": [{"source": "file tags", "source_id": "embedded_tag", "value": "Dust Road", "confidence": 1.0}]},
  "duplicate_candidates": [{"book_id": 42, "title": "Dust Road", "match_reasons": ["title_author"], "confidence": 0.95}],
  "target_candidates": [
    {"id": "recommended", "relative_directory": "Fantasy/Jane Author/Dust Road", "available": false, "duplicate_actions": ["create_new", "skip"]},
    {"id": "renamed", "relative_directory": "Fantasy/Jane Author/Dust Road_01", "available": true, "duplicate_actions": ["create_new", "skip"]},
    {"id": "existing_book", "relative_directory": "Fantasy/Jane Author/Dust Road", "available": true, "duplicate_actions": ["replace", "skip"]}
  ],
  "warnings": [{"id": "duplicate_found", "code": "duplicate_found", "message": "...", "requires_acknowledgment": false}],
  "required_decisions": [
    {"id": "duplicate_action", "type": "duplicate_action", "plan_field": "duplicate_action", "options": ["skip", "replace", "create_new"], "default": "skip", "related_book_id": 42},
    {"id": "target", "type": "target", "plan_field": "target.candidate_id", "options": ["renamed", "existing_book"], "default": "renamed"},
    {"id": "file_operation", "type": "file_operation", "plan_field": "file_operation", "options": ["copy", "move"], "default": "copy"}
  ],
  "identifiers": {"isbn": null}
}
```

Target directories are always relative to the library root. Duplicate options mirror
`book:import`: an existing copy with audio offers `skip`/`replace`/`create_new` (default
`skip`); an existing record without audio offers `merge`/`skip` (default `merge`); no duplicate
offers `create_new` (plus `skip` when only similar titles were found). Warnings with
`requires_acknowledgment: true` (currently the client's `source.warnings`) add an
`acknowledged_warning_ids` decision of type `acknowledgment`.

### Events

`GET /imports/drafts/{draftId}/events?after=<cursor>` (or `Last-Event-ID`) returns events oldest
first with `next_cursor` and `has_more`. `next_cursor` is always present; pass it back unchanged.
Polling this or `GET /imports/drafts/{draftId}` is the supported way to follow progress.

## Rules

- Paths are relative, POSIX, NFC-normalized and unique case-insensitively. Absolute, drive-letter,
  backslash, `.`/`..`/empty-segment and control-character paths are rejected with 422.
- Errors: `{ "contract_version": "imports.v1", "error": { "code", "message", "details", "retryable" } }`.
- Retrying a POST with the same `Idempotency-Key` and body replays the stored response
  (`Idempotent-Replayed: true`); a different body with the same key is `422 idempotency_key_reused`.
- Only the owner (or an admin) can read or cancel a draft; others get 404.
