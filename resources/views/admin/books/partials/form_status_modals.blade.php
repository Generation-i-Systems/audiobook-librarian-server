        {{-- Delete Book Modal --}}
        @if(isset($book))
        <div class="modal fade" id="deleteBookModal" tabindex="-1" aria-labelledby="deleteBookModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="deleteBookModalLabel">Delete Book?</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2"><strong>{{ $book['title'] }}</strong></p>
                        @if(!empty($book['directoryPath']))
                            <p class="text-muted small mb-3">Directory: <code>{{ $book['directoryPath'] }}</code></p>
                        @endif

                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" id="deleteFilesCheckbox" checked>
                            <label class="form-check-label" for="deleteFilesCheckbox">
                                Also delete files from disk (moved to trash)
                            </label>
                        </div>

                        <div class="alert alert-info mt-3 mb-0">
                            <small><i class="fas fa-info-circle me-2"></i>Files will be moved to trash and can be restored from the admin trash page.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <form id="deleteBookForm" action="{{ route('admin.books.destroy', $book['id']) }}" method="POST" style="display:inline;">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="delete_files" id="deleteFilesInput" value="true">
                            @if($finalReturnUrl ?? null)
                                <input type="hidden" name="return_url" value="{{ $finalReturnUrl }}">
                            @else
                                <input type="hidden" name="return_url" value="{{ route('admin.books.index') }}">
                            @endif
                            <button type="submit" class="btn btn-danger">Delete Book</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Handle delete files checkbox
        document.getElementById('deleteFilesCheckbox')?.addEventListener('change', function() {
            document.getElementById('deleteFilesInput').value = this.checked ? 'true' : 'false';
        });

        // Refresh CSRF token when delete modal opens (prevents 419 errors on long-open pages)
        const deleteModal = document.getElementById('deleteBookModal');
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function() {
                fetch('/csrf-token', {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json',
                    }
                })
                .then(response => response.json())
                .then(data => {
                    const tokenInput = document.querySelector('#deleteBookForm input[name="_token"]');
                    if (tokenInput && data.csrf_token) {
                        tokenInput.value = data.csrf_token;
                        console.log('Delete form CSRF token refreshed');
                    }
                })
                .catch(error => {
                    console.error('Failed to refresh CSRF token:', error);
                });
            });
        }
        </script>

        {{-- Shared Directory Confirmation Modal --}}
        @if(session('requires_confirmation') && session('book_id'))
        <div class="modal fade" id="sharedDirectoryModal" tabindex="-1" aria-labelledby="sharedDirectoryModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title" id="sharedDirectoryModalLabel">Shared Directory Warning</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>This book shares a directory with other books!</strong>
                        </div>
                        <p class="mb-2"><strong>Book:</strong> {{ session('book_title') }}</p>
                        <p class="text-muted small mb-3"><strong>Shared directory:</strong> <code>{{ session('shared_directory') }}</code></p>
                        <p><strong>You can only delete the database record. The files will remain on disk for the other books.</strong></p>
                        <p class="mb-0">Do you want to proceed with deleting only the database record?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <form action="{{ route('admin.books.destroy', session('book_id')) }}" method="POST" style="display:inline;">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="delete_files" value="false">
                            <input type="hidden" name="confirmed" value="true">
                            @if(session('return_url'))
                                <input type="hidden" name="return_url" value="{{ session('return_url') }}">
                            @elseif($finalReturnUrl ?? null)
                                <input type="hidden" name="return_url" value="{{ $finalReturnUrl }}">
                            @else
                                <input type="hidden" name="return_url" value="{{ route('admin.books.index') }}">
                            @endif
                            <button type="submit" class="btn btn-danger">Delete Database Record Only</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Auto-show shared directory modal
        document.addEventListener('DOMContentLoaded', function() {
            const sharedDirModal = document.getElementById('sharedDirectoryModal');
            if (sharedDirModal) {
                const modal = new bootstrap.Modal(sharedDirModal);
                modal.show();
            }
        });
        </script>
        @endif
        @endif

        {{-- Move Failure Confirmation Modal --}}
        @if(session('move_failed'))
        <div class="modal fade" id="moveFailureModal" tabindex="-1" aria-labelledby="moveFailureModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="moveFailureModalLabel">Directory Move Failed</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Failed to move files to new directory</strong>
                        </div>
                        <p class="mb-2"><strong>Error:</strong></p>
                        <p class="text-muted small mb-3">{{ session('move_error') }}</p>
                        <p class="mb-2"><strong>Old directory:</strong> <code>{{ session('old_directory_path') }}</code></p>
                        <p class="mb-3"><strong>New directory:</strong> <code>{{ session('new_directory_path') }}</code></p>

                        <p class="mb-2"><strong>What would you like to do?</strong></p>
                        <ul>
                            <li><strong>Continue Anyway:</strong> Update the database with the new directory path without moving files. Files will remain in the old location.</li>
                            <li><strong>Revert:</strong> Cancel the update and return to editing the book.</li>
                        </ul>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Revert</button>
                        <form action="{{ route('admin.books.update', $book['id']) }}" method="POST" style="display:inline;">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="continue_without_move" value="true">
                            @foreach(old() as $key => $value)
                                @if($key !== '_token' && $key !== '_method')
                                    @if(is_array($value))
                                        @foreach($value as $subkey => $subvalue)
                                            @if(is_array($subvalue))
                                                @foreach($subvalue as $subsubkey => $subsubvalue)
                                                    <input type="hidden" name="{{ $key }}[{{ $subkey }}][{{ $subsubkey }}]" value="{{ $subsubvalue }}">
                                                @endforeach
                                            @else
                                                <input type="hidden" name="{{ $key }}[{{ $subkey }}]" value="{{ $subvalue }}">
                                            @endif
                                        @endforeach
                                    @else
                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                    @endif
                                @endif
                            @endforeach
                            <button type="submit" class="btn btn-warning">Continue Anyway (Database Only)</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Auto-show move failure modal
        document.addEventListener('DOMContentLoaded', function() {
            const moveFailureModal = document.getElementById('moveFailureModal');
            if (moveFailureModal) {
                const modal = new bootstrap.Modal(moveFailureModal);
                modal.show();
            }
        });
        </script>
        @endif
