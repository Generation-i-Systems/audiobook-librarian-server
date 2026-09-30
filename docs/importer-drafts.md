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
| `PATCH /imports/drafts/{draftId}` | Save reviewed metadata edits; `If-Match` + `Idempotency-Key` required (phase 3) |
| `POST /imports/drafts/{draftId}/approve` | Validate and lock an `ImportPlan` (requires `Idempotency-Key`) (phase 3) |
| `POST /imports/drafts/{draftId}/uploads` | Open resumable upload sessions for approved files (phase 5) |
| `HEAD /imports/drafts/{draftId}/uploads/{fileId}` | Confirmed `Upload-Offset` / `Upload-Length` (phase 5) |
| `PATCH /imports/drafts/{draftId}/uploads/{fileId}` | Append one chunk at `Upload-Offset` (phase 5) |
| `POST /imports/drafts/{draftId}/verify` | Re-hash staged files and bind them to the plan revision (phase 5) |

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

The `target` decision offers only candidates with `available: true`. Its `default` is the first
of those whose `duplicate_actions` include the `duplicate_action` decision's default. It is
**`null`** when no available candidate fits that default action, for example when the title or
author is missing (no candidates at all), or when the usual folder is occupied and no renamed
folder can be offered. The server never invents a destination; the client must ask the user
to choose one of `options` (possibly after choosing a different duplicate action) or to edit the
details.

### Refreshing destinations

Destination availability is computed from the library folders when the recommendation is
derived, and is not rechecked by `GET`. The server re-derives duplicates, destinations, policy
warnings and `required_decisions` from the draft's **stored** metadata (never changing the
metadata or its provenance) when:

- an approval finds the chosen destination unavailable (`target_unavailable`), and
- a `PATCH` changes nothing (a no-op edit is the way to ask for a recheck).

If the result differs from the stored recommendation, the revision is bumped once and one
`targets_refreshed` event is recorded with
`{"trigger": "approval" | "review_patch", "unavailable_candidate_ids": [...]}`. If nothing
differs, the revision stays the same and no event is recorded.

An `approved` draft is never rewritten this way, because its locked plan was built from the old
recommendation: a no-op `PATCH` whose recheck would change anything returns
`409 approved_plan_stale` (`details: {plan_revision, current_revision}`) and changes nothing.
Editing a detail for real invalidates the plan and reopens review, as described below.

### Events

`GET /imports/drafts/{draftId}/events?after=<cursor>` (or `Last-Event-ID`) returns events oldest
first with `next_cursor` and `has_more`. `next_cursor` is always present; pass it back unchanged.
Polling this or `GET /imports/drafts/{draftId}` is the supported way to follow progress.

## Review and approval (phase 3)

### Editing (`PATCH /imports/drafts/{draftId}`)

- Body is a JSON merge patch (`application/merge-patch+json` or `application/json`) with a
  `metadata` object. `series` merges key by key; other fields replace. `cover_artifact_id` may
  name one of the draft's artifacts.
- `If-Match` is required (`428 revision_required` without it, `409 draft_revision_conflict` with a
  stale value). `Idempotency-Key` is required.
- Invalid values (blank title, non-list authors, negative series number, series number without a
  name, unknown fields) are rejected with `422 validation_failed`; they are never rewritten.
  `local_ai_artifacts` returns `422 local_ai_artifacts_not_supported` for now.
- A no-op edit rechecks destinations (see [Refreshing destinations](#refreshing-destinations)):
  the draft is returned with the same revision unless the recheck changed the recommendation, in
  which case the revision is bumped once and a `targets_refreshed` event is recorded. On an
  `approved` draft a recheck that would change anything fails with `409 approved_plan_stale`.
  A real edit bumps the revision,
  prepends a `your edit` (`source_id: user_edit`) provenance entry to each changed field and
  re-derives duplicates, targets, warnings and `required_decisions` from the edited metadata. A
  `metadata_updated` event carries `{fields}`.
- Only `awaiting_review` and `approved` drafts are editable (`409 invalid_state_transition`
  otherwise). Editing an `approved` draft invalidates its plan (`invalidated_reason:
  metadata_edited_after_approval`, `plan_invalidated` event), clears `plan_revision` and returns
  the draft to `awaiting_review`; the client must approve again.

### Approving (`POST /imports/drafts/{draftId}/approve`)

The body is an `ImportsPlanApproval`: `contract_version`, `expected_revision`, `metadata`,
optional `cover_artifact_id`, `target.candidate_id`, `duplicate_action`, `file_operation`,
`transfer_mode` and `acknowledged_warning_ids`. Checks, in order:

| Code | Status | Meaning |
|---|---|---|
| `validation_failed` | 422 | Wrong shape, unknown keys, contract version, or missing title/author |
| `draft_revision_conflict` | 409 | `expected_revision` (or `If-Match`) is not the current revision |
| `invalid_state_transition` | 409 | Draft is not `awaiting_review` |
| `metadata_not_reviewed` | 422 | Title, authors, narrators, series or genres differ from the recommendation; save them via PATCH first so targets and duplicates are re-checked |
| `invalid_genre` | 422 | Genre is not in the library list; `details.suggestion` is the closest valid genre |
| `invalid_duplicate_action` | 422 | Action is not an option of the `duplicate_action` decision |
| `invalid_target` | 422 | Unknown candidate id |
| `target_incompatible` | 422 | The candidate does not allow that duplicate action |
| `target_unavailable` | 422 | The folder is occupied (rechecked live at approval), unless the action is `skip`. The destinations are refreshed first; `details: {candidate_id, current_revision, targets_refreshed}`. Reload the draft (`GET`) and approve again with `current_revision` |
| `invalid_transfer_mode` / `invalid_file_operation` | 422 | Not offered (`upload` allows `copy` or `move`) |
| `invalid_cover_artifact` | 422 | Cover id is not an artifact of this draft |
| `unknown_warning` / `warnings_not_acknowledged` | 422 | Acknowledgements must match warnings that require one |

On success the plan is stored exactly as sent (description, tags and language included, byte for
byte), with the resolved relative target directory, the duplicate book id, a manifest snapshot and
the recommendation snapshot. The draft becomes `approved`, `revision` and `plan_revision` both
become the plan revision, and `draft.plan` returns the locked plan. A retry with the same
`Idempotency-Key` replays the response; a second approval of an approved draft is rejected.

## Resumable uploads (phase 5)

After approval a client with `transfer_mode: upload` sends every manifest file with more than
zero bytes (all roles). Zero-byte files are staged by the server and never uploaded.

1. `POST /uploads` (`If-Match` = current draft revision, `Idempotency-Key`) with
   `{plan_revision, files: [{file_id, sha256, bytes}]}`. `plan_revision` must be the draft's live,
   non-invalidated plan (else `409 approved_plan_stale {plan_revision, current_revision}`); every
   file must be in the plan's manifest with the same `bytes`, and a manifest `sha256`, when known,
   must match (`422 unknown_file` / `upload_manifest_mismatch`). Returns `201`
   `{contract_version, chunk_size, uploads: [{file_id, upload_url, offset}]}` and a new `ETag`.
   `upload_url` is relative to `/api/v1/`. Calls are cumulative and report the confirmed offset, so
   calling it again (with a new key) after a restart is a safe resume. The first call moves the draft
   `approved` -> `transferring`.
2. `HEAD /uploads/{fileId}` returns `204` with `Upload-Offset`, `Upload-Length` and
   `Cache-Control: no-store` (`404 upload_not_found` without a session).
3. `PATCH /uploads/{fileId}` with `Content-Type: application/offset+octet-stream` (`415` otherwise)
   and `Upload-Offset` equal to the confirmed offset (`409 upload_offset_conflict {expected_offset}`
   otherwise, nothing written). Bodies over `max_upload_chunk_bytes` are `413 chunk_too_large`; past
   the file length `422 upload_length_exceeded`. Returns `204` with the new `Upload-Offset`. When the
   last byte arrives the staged file is hashed: a mismatch discards it, resets the offset to 0 and
   returns `422 upload_hash_mismatch {file_id, reason}`. When every file is uploaded the draft moves to
   `verifying`.
4. `POST /verify` (`If-Match`, `Idempotency-Key`, body ignored) re-hashes every staged file, checks
   the staged layout and records a `transfer_verified` event binding the files to the plan revision
   (`import_drafts.transfer_verified_plan_revision`). `transfer.state` becomes `verified`; the draft
   stays `verifying`. Nothing is queued or imported. `422 upload_incomplete {file_ids}`,
   `422 transfer_verification_failed {file_ids}` (those files restart at 0),
   `422 staged_layout_invalid {file_ids}`.

Bytes are staged privately under `IMPORT_DRAFT_STAGING_ROOT` (default
`storage/app/import-staging/{draft id}/{file row id}.part`); the database offset is authoritative
and each append truncates the staged file to it first. Appends to one draft are serialized under a
row lock on the draft. The revision is bumped when the transfer state changes or a file completes or
is rejected, not for each chunk. Events: `transfer_progress {file_id, bytes_received, bytes_total}`
(each 5% step and on completion), `transfer_file_rejected {file_id, reason}`, `transfer_verified`,
`transfer_reset`.

Cancelling deletes the staged bytes. Editing the metadata of an approved, transferring or
verifying draft invalidates the plan, resets every file and deletes the staged bytes.
`imports:purge-staging` (hourly) deletes staged bytes of cancelled/failed/expired drafts older than
`IMPORT_DRAFT_STAGING_RETENTION_HOURS` (default 24); directories whose draft is not in the active
database are left alone. Session creation/verify and chunk appends have separate per-user rate
limits (30/min and 1200/min).

Make sure the web server and PHP accept request bodies of at least one chunk (16 MiB by default):
nginx `client_max_body_size`, PHP `post_max_size`.

## Rules

- Paths are relative, POSIX, NFC-normalized and unique case-insensitively. Absolute, drive-letter,
  backslash, `.`/`..`/empty-segment and control-character paths are rejected with 422.
- Errors: `{ "contract_version": "imports.v1", "error": { "code", "message", "details", "retryable" } }`.
- Retrying a POST with the same `Idempotency-Key` and body replays the stored response
  (`Idempotent-Replayed: true`); a different body with the same key is `422 idempotency_key_reused`.
- Only the owner (or an admin) can read or cancel a draft; others get 404.
