        {{-- Series Rename Modal --}}
        <div class="modal fade" id="renameSeriesModal" tabindex="-1" aria-labelledby="renameSeriesModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="renameSeriesModalLabel">{{ __('Rename Series') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>{{ __('Warning:') }}</strong> This will rename the series for ALL books in this series, not just this book.
                        </div>
                        <div class="mb-3">
                            <label for="old-series-name" class="form-label">{{ __('Current Series Name') }}</label>
                            <input type="text" class="form-control" id="old-series-name" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="new-series-name" class="form-label">{{ __('New Series Name') }}</label>
                            <input type="text" class="form-control" id="new-series-name" placeholder="{{ __('Enter new series name') }}">
                        </div>
                        <div id="rename-series-feedback"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="button" class="btn btn-primary" id="confirm-rename-series-btn">{{ __('Rename Series') }}</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Related Books Modal (other books by an author / in a series) --}}
        <div class="modal fade" id="relatedBooksModal" tabindex="-1" aria-labelledby="relatedBooksModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="relatedBooksModalLabel">{{ __('Other Books') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div id="related-books-list">
                            <div class="text-center p-4">
                                <div class="spinner-border" role="status">
                                    <span class="visually-hidden">{{ __('Loading...') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Directory Browser Modal --}}
        <div class="modal fade" id="directoryBrowserModal" tabindex="-1" aria-labelledby="directoryBrowserModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="directoryBrowserModalLabel">{{ __('Browse Directories') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">{{ __('Current Path:') }}</label>
                            <div class="d-flex align-items-center">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="dir-browser-up-btn" disabled>
                                    <i class="fas fa-arrow-up"></i> Up
                                </button>
                                <input type="text" class="form-control" id="dir-browser-current-path" readonly>
                            </div>
                        </div>
                        <div class="border rounded p-3" style="max-height: 400px; overflow-y: auto;">
                            <div id="dir-browser-list">
                                <div class="text-center text-muted">{{ __('Loading...') }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="button" class="btn btn-primary" id="dir-browser-select-btn">{{ __('Select This Directory') }}</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Directory Conflict Resolution Modal --}}
        <div class="modal fade" id="directoryConflictModal" tabindex="-1" aria-labelledby="directoryConflictModalLabel" aria-hidden="true" data-bs-backdrop="static">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title" id="directoryConflictModalLabel">
                            <i class="fas fa-exclamation-triangle me-2"></i>Directory Path Conflict
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <strong>{{ __('Warning:') }}</strong> The directory path you entered is already used by another book.
                        </div>
                        <div id="conflict-book-info" class="mb-3">
                            <p class="mb-2"><strong>{{ __('Conflicting book:') }}</strong></p>
                            <ul id="conflict-book-list" class="list-unstyled ms-3">
                            </ul>
                        </div>
                        <p class="mb-3">{{ __('What would you like to do?') }}</p>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-primary" id="conflict-change-path-btn">
                                <i class="fas fa-edit me-2"></i>Change This Book's Directory Path
                            </button>
                            <button type="button" class="btn btn-warning" id="conflict-merge-btn">
                                <i class="fas fa-compress-arrows-alt me-2"></i>Merge Directories (Keep Both Books)
                            </button>
                            <button type="button" class="btn btn-danger" id="conflict-move-other-btn" style="display: none;">
                                <i class="fas fa-exchange-alt me-2"></i>Move Other Book's Directory
                            </button>
                        </div>
                        <div class="mt-3">
                            <small class="text-muted">
                                <strong>{{ __('Note:') }}</strong> Merging will combine all files from both directories. Moving will relocate the other book's files.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Audio Metadata Modal --}}
        <div class="modal fade" id="audioMetadataModal" tabindex="-1" aria-labelledby="audioMetadataModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="audioMetadataModalLabel">{{ __('Audio File Metadata') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div id="metadata-content">
                            <div class="text-center p-4">
                                <div class="spinner-border" role="status">
                                    <span class="visually-hidden">{{ __('Loading...') }}</span>
                                </div>
                                <p class="mt-2 text-muted">{{ __('Loading metadata...') }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                    </div>
                </div>
            </div>
        </div>

        @push('scripts')
            <script>
            </script>

            <script type="text/javascript">
                // Define global route objects for book form
                window.BOOK_FORM_ROUTES = {
                    index: "{{ route('admin.books.index') }}",
                    search: "{{ route('admin.books.search') }}",
                    googleBooks: "{{ route('admin.books.googleBooks') }}",
                    audible: "{{ route('admin.books.audible') }}",
                    filesAjax: "{{ route('admin.books.filesAjax') }}",
                    audioMetadata: "{{ route('admin.books.audioMetadata') }}",
                    authorsAutocomplete: "{{ route('admin.books.autocomplete.authors') }}",
                    seriesAutocomplete: "{{ route('admin.books.autocomplete.series') }}",
                    narratorsAutocomplete: "{{ route('admin.books.autocomplete.narrators') }}",
                    renameSeries: "{{ route('admin.books.renameSeries') }}",
                    relatedBooks: "{{ route('admin.books.relatedAjax') }}"
                };

                // Set other global variables
                window.APP_URL = "{{ config('app.url') }}";
                window.GENRE_OPTIONS = @json(config('genres.list', []));
                window.AUDIBLE_SEARCH_URL = "{{ route('admin.books.audible') }}";
                window.BOOK_FORM_ROUTES.browseDirectories = "{{ route('admin.books.browseDirectories') }}";
                window.BOOK_FORM_ROUTES.parsePath = "{{ route('admin.books.parsePath') }}";
                window.BOOK_FORM_ROUTES.checkDirectoryConflict = "{{ route('admin.books.checkDirectoryConflict') }}";
                window.BOOK_FORM_ROUTES.buildPathFromFields = "{{ route('admin.books.buildPathFromFields') }}";
                @if(isset($book) && !empty($book['id']))
                    window.BOOK_FORM_ROUTES.playAudio = "{{ route('admin.books.playAudio', ['book' => $book['id'], 'file' => '__FILE__']) }}";
                    window.BOOK_FORM_ROUTES.plannedActions = "{{ route('admin.books.plannedActions', ['id' => $book['id']]) }}";
                    window.BOOK_FORM_ROUTES.executeImmediateMove = "{{ route('admin.books.executeImmediateMove', ['id' => $book['id']]) }}";
                    window.BOOK_ID = "{{ $book['id'] }}";
                @endif

                // Debug: Confirm jQuery and jQuery UI are loaded
                console.log('window.jQuery:', typeof window.jQuery, window.jQuery ? 'OK' : 'MISSING');
                console.log('$.fn.autocomplete:', typeof $.fn.autocomplete, $.fn.autocomplete ? 'OK' : 'MISSING');
            </script>

            {{-- Include book form scripts via Vite --}}
            @vite([
                'resources/js/admin/books/form.js',
                'resources/js/admin/books/form-helpers.js',
                'resources/js/admin/books/init-book-form.js',
                'resources/js/admin/books/form-autocomplete.js',
                'resources/js/admin/books/tag-editor.js',
                'resources/js/admin/books/autofill-simple.js',
                'resources/js/admin/books/form-cover.js',
                'resources/js/admin/books/form-directory.js',
                'resources/js/admin/books/planned-actions.js',
                'resources/js/admin/books/directory-browser.js',
                'resources/js/admin/books/series-rename.js',
                'resources/js/admin/books/directory-conflict.js',
                'resources/js/admin/books/save-button-spinner.js',
                'resources/js/admin/books/related-books-modal.js'
            ])
            <script type="text/javascript">
            $(function() {
                var formSelector = '#book-form';
                if (typeof window.initBookForm === 'function') {
                    console.log('Calling initBookForm for selector', formSelector);
                    window.initBookForm(formSelector);
                } else {
                    console.error('initBookForm is not defined!');
                }
            });
            </script>
            <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                var autofillModal = document.getElementById('autofillModal');
                if (autofillModal) {
                    autofillModal.addEventListener('show.bs.modal', function () {
                        // Get current form values
                        var title = document.querySelector('input[name="title"]')?.value || '';
                        var author = '';
                        var authorField = document.querySelector('input[name="author[]"]');
                        if (authorField) {
                            author = authorField.value;
                        }
                        var series = '';
                        var seriesField = document.querySelector('input[name="series[]"]');
                        if (seriesField) {
                            series = seriesField.value;
                        }
                        // Set modal fields
                        document.getElementById('autofill-title').value = title;
                        document.getElementById('autofill-author').value = author;
                        document.getElementById('autofill-series').value = series;
                    });
                }
            });
            </script>
        @endpush
        <!-- Raw JSON Edit Modal -->
        <div class="modal fade" id="rawJsonModal" tabindex="-1" aria-labelledby="rawJsonModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-lg">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="rawJsonModalLabel">{{ __('Edit Book Raw JSON') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
              </div>
              <div class="modal-body">
                <div id="raw-json-error" class="alert alert-danger d-none" role="alert" style="display:none;"></div>
                <textarea id="raw-json-textarea" class="form-control font-monospace" rows="18" style="font-size:0.98em;"></textarea>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-primary" id="save-raw-json-btn">{{ __('Save JSON') }}</button>
              </div>
            </div>
          </div>
        </div>

        <div class="modal fade" id="autofillModal" tabindex="-1" aria-labelledby="autofillModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="true">
          <div class="modal-dialog modal-lg">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="autofillModalLabel">{{ __('Autofill Book Metadata') }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
              </div>
              <div class="modal-body autofill-modal-scrollable">
                <div id="autofill-search-form" class="mb-3">
                  <div class="row g-2 mb-2">
                    <div class="col-md-3">
                      <label for="autofill-title" class="form-label">{{ __('Title') }}</label>
                      <input type="text" class="form-control" id="autofill-title" name="title" maxlength="120" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                      <label for="autofill-author" class="form-label">{{ __('Author') }}</label>
                      <input type="text" class="form-control" id="autofill-author" name="author" maxlength="120" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                      <label for="autofill-series" class="form-label">{{ __('Series') }}</label>
                      <input type="text" class="form-control" id="autofill-series" name="series" maxlength="120" autocomplete="off">
                    </div>
                    <div class="col-md-3">
                      <label for="autofill-api-id" class="form-label">API ID (Optional)</label>
                      <input type="text" class="form-control" id="autofill-api-id" name="api_id" placeholder="{{ __('ASIN, Google ID, etc') }}" autocomplete="off">
                    </div>
                  </div>
                  <div class="row g-2 mb-2">
                    <div class="col-12">
                      <label class="form-label">{{ __('Search Sources') }}</label>
                      <div class="btn-group w-100" role="group">
                        <button type="button" class="btn btn-outline-primary" data-source="audible" id="search-audible-btn">
                          <i class="fas fa-headphones me-1"></i> Audible
                        </button>
                        <button type="button" class="btn btn-outline-primary" data-source="google" id="search-google-btn">
                          <i class="fas fa-book me-1"></i> Google Books
                        </button>
                        <button type="button" class="btn btn-outline-primary" data-source="audiobookbay" id="search-audiobookbay-btn">
                          <i class="fas fa-ship me-1"></i> AudiobookBay
                        </button>
                        <button type="button" class="btn btn-outline-primary" data-source="hardcover" id="search-hardcover-btn">
                          <i class="fas fa-book-open me-1"></i> Hardcover
                        </button>
                        <button type="button" class="btn btn-outline-success" id="search-all-btn" title="{{ __('Search all sources') }}">
                          <i class="fas fa-search"></i>
                        </button>
                      </div>
                    </div>
                  </div>
                  <div class="row g-2">
                    <div class="col-12">
                      <div id="autofill-modal-feedback" class="alert alert-danger d-none" style="display:none;"></div>
                    </div>
                  </div>
                </div>
                <div class="table-responsive" id="autofill-results-wrapper" style="display:none;">
                  <table class="table table-bordered table-hover align-middle mb-0" id="autofill-results-table">
                    <thead class="table-light">
                      <tr>
                        <th scope="col">{{ __('Select') }}</th>
                        <th scope="col">{{ __('Cover') }}</th>
                        <th scope="col">{{ __('Title') }}</th>
                        <th scope="col">{{ __('Author') }}</th>
                        <th scope="col">{{ __('Narrator') }}</th>
                        <th scope="col">{{ __('Series') }}</th>
                        <th scope="col">{{ __('Genre') }}</th>
                        <th scope="col">{{ __('Year') }}</th>
                        <th scope="col">{{ __('Source') }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <!-- Results will be injected here by JS -->
                    </tbody>
                  </table>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <button type="button" class="btn btn-success" id="autofill-apply-btn" disabled>{{ __('Apply') }}</button>
              </div>
            </div>
          </div>
        </div>

        {{-- Removed duplicate book-autocomplete.js - form.js already handles autocomplete with jQuery UI --}}

        {{-- Audio player modal --}}
        <div class="modal fade" id="audioModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title mb-0" id="audioModalTitle"></h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center py-3">
                        <audio id="audioPlayer" controls style="width:100%;" preload="metadata">{{ __('Your browser does not support audio.') }}</audio>
                    </div>
                </div>
            </div>
        </div>

        {{-- Image viewer modal --}}
        <div class="modal fade" id="imageModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title mb-0" id="imageModalTitle"></h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center p-2">
                        <img id="imageModalImg" src="" alt="" style="max-width:100%;max-height:80vh;object-fit:contain;">
                    </div>
                </div>
            </div>
        </div>
