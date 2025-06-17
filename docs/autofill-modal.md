# Autofill Modal UI/UX and API Integration

## Overview
The Autofill modal for the Book Edit/Create form allows users to search for book metadata from multiple external sources and apply the results to the form. Supported sources include Audible, Google Books, AudiobookBay, and Hardcover. The modal provides advanced search capabilities and a preview/selection UI.

## Features
- Modal can be opened from the book form (edit/create)
- Search fields for:
  - Title
  - Author
  - Series
  - API-specific ID (e.g., Audible ASIN, Google Books ID, AudiobookBay slug, Hardcover ID)
- API Source selector: Audible, Google Books, AudiobookBay, Hardcover
- Search can be performed by regular fields or by a specific API ID
- Results previewed in a table/grid with relevant columns (cover, title, author, series, year, source)
- User can select one result to apply to the book form
- Applying a result fills in the book form fields (title, author, series, year, cover, etc)

## Workflow
1. User clicks "Autofill Book Metadata"
2. Modal appears with search fields and API selector
3. User enters search criteria (or API ID) and selects a source
4. User clicks "Search"
5. Results are shown in a table/grid
6. User selects a result and clicks "Apply"
7. Book form fields are populated from the selected result

## Notes
- Search fields are validated; at least one must be provided
- If searching by API ID, only the ID field is required
- API integration is modular; each source has a backend service/provider
- Table preview updates dynamically on search
- UI/UX is responsive and works in modal and non-modal flows

## To Build
- Modal UI in Blade
- JS for search, table preview, selection, and apply
- Backend endpoints for each API
- Tests for all flows
- Documentation and changelog updates

---

This doc summarizes the user prompt and the intended functionality for the Autofill modal as of 2025-06-17.
