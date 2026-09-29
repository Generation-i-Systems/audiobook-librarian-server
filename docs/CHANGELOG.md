# OpenAPI Documentation Changelog

## 2026-09-29

### Added

- Added `serverName` to the `/health/capabilities` response schema.
- Documented the imports.v1 draft endpoints (`/imports/capabilities`, `/imports/drafts`, `/imports/drafts/{draftId}`, `/imports/drafts/{draftId}/cancel`) and their `Imports*` component schemas, merged from the importer repository's `docs/imports-v1.openapi.yaml`.

## 2026-05-08

### Added

- Documented the existing `GET /tags/all` endpoint in `openapi.json`.
- Documented per-user book tag endpoints in `openapi.json`
- Documented tag filtering support on book list and search endpoints

## 2026-01-15

### Added

- Documented Book Recommendations API endpoints in `openapi.json`
- Documented Book Status & Queue Management API endpoints in `openapi.json`
- Created human-readable technical documentation for mobile developers in `docs/api/recommendations-and-tracking.md`
- Added project rule to `AGENTS.md` requiring API documentation for all changes

## 2025-07-10

### Added

- Created `generate_openapi_json.py` script for robust YAML to JSON conversion
- Added proper schema extraction logic to handle nested YAML structures
- Generated complete JSON version of the OpenAPI specification
- Created README.md with documentation on available scripts and usage

### Fixed

- Fixed YAML syntax issues in openapi.yaml
- Improved schema extraction to properly handle indentation and nested properties
- Ensured proper OpenAPI JSON structure for schemas and security schemes

### Changed

- Enhanced the schema extraction logic to better handle complex YAML structures
- Updated the JSON output to include all necessary components (info, servers, paths, schemas, security schemes)
