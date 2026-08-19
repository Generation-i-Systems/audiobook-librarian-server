# Host-Based Library Profiles (Single Runtime, Multiple Libraries)

- The same running server can serve multiple isolated library variants based on incoming host name.
- Configure host-to-profile routing in `config/library_profiles.php` using environment variables.
- Each profile can override:
    - database connection
    - `books` disk root / `BOOK_STORAGE_PATH`
    - source mode metadata (`local`, `librivox`, etc.)
- The routing is request-scoped through `App\Http\Middleware\ResolveLibraryProfileFromHost`, so the API contract remains identical while data source and file roots change by host.

## Environment variables

- For multiple production aliases pointing at the same "main" profile, set
  `LIBRARY_PROFILE_MAIN_HOSTS` to a comma-separated list of hostnames.
  Provision DNS and TLS for each name; the application uses the incoming host when generating
  links and QR connection URLs.

- `LIBRARY_PROFILE_DEFAULT`
- `LIBRARY_PROFILE_FALLBACK_TO_DEFAULT`
- `LIBRARY_PROFILE_MAIN_HOSTS`
- `LIBRARY_PROFILE_MAIN_DB_CONNECTION`
- `LIBRARY_PROFILE_MAIN_BOOK_STORAGE_PATH`
- `LIBRARY_PROFILE_MAIN_SOURCE_MODE`
- `LIBRARY_PROFILE_LIBRIVOX_HOSTS`
- `LIBRARY_PROFILE_LIBRIVOX_DB_CONNECTION`
- `LIBRARY_PROFILE_LIBRIVOX_BOOK_STORAGE_PATH`
- `LIBRARY_PROFILE_LIBRIVOX_SOURCE_MODE`

Most single-server, single-library deployments do not need this — it only matters if one running
instance should serve more than one distinct library/database depending on hostname.
