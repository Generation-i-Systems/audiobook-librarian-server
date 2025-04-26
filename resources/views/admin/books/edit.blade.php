@extends(isset($layout) ? $layout : 'layouts.app')

@section('content')
    <div class="container">
        @if(empty($isModal))
            <h1>Edit Book</h1>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @include('admin.books.form', [
            'book' => $book,
            'authorList' => $authorList,
            'seriesList' => $seriesList,
            'genreList' => $genreList,
            'coverCandidates' => $coverCandidates,
            'coverAuto' => $coverAuto,
            'directory_path' => $book->directory_path,
            'isModal' => $isModal ?? false
        ])

    </div>

@endsection

@section('scripts')
    <script>
        $(document).ready(function () {
            $('#autofill-btn').on('click', function () {
                const title = $('#title').val();
                const authorSelect = $('#author-select');
                const authorName = authorSelect.length ? authorSelect.find('option:selected').text() : '';
                if (!title || !authorName || authorName === 'Select Author') {
                    alert('Please enter both title and author to autofill.');
                    return;
                }
                fetch(`{{ route('admin.books.googleBooks') }}?title=${encodeURIComponent(title)}&author=${encodeURIComponent(authorName)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            alert(data.error);
                        } else {
                            if (data.published_year) {
                                $('#published_year').val(data.published_year);
                            }
                            if (data.description) {
                                $('#description').val(data.description);
                            }
                            if (data.cover_image_url) {
                                $('#cover-preview-img').attr('src', data.cover_image_url);
                                $('#cover-preview-group').show();
                            }
                            let hidden = $('#cover_image_url');
                            if (!hidden.length) {
                                $('<input>').attr({ type: 'hidden', id: 'cover_image_url', name: 'cover_image_url' }).appendTo('#book-edit-form');
                                hidden = $('#cover_image_url');
                            }
                            hidden.val(data.cover_image_url || '');
                        }
                    })
                    .catch(() => alert('Failed to fetch book info.'));
            });
            if (typeof window.bootstrap !== 'undefined' && $('#modal-cancel-btn').length) {
                $('#modal-cancel-btn').on('click', function () {
                    var modalEl = document.getElementById('addBookModal');
                    var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                    if (bsModal) bsModal.hide();
                });
            }
            if (typeof window.bootstrap !== 'undefined' && $('#modal-update-btn').length && $('#book-edit-form').closest('.modal').length) {
                $('#book-edit-form').on('submit', function (e) {
                    e.preventDefault();
                    var form = $(this);
                    var url = form.attr('action');
                    var method = form.find('input[name="_method"]').val() || form.attr('method');
                    var formData = new FormData(this);
                    $.ajax({
                        url: url,
                        type: method,
                        data: formData,
                        processData: false,
                        contentType: false,
                        success: function (data) {
                            var modalEl = document.getElementById('addBookModal');
                            var bsModal = window.bootstrap.Modal.getInstance(modalEl);
                            if (bsModal) bsModal.hide();
                        },
                        error: function (xhr) {
                            let msg = 'Failed to update book.';
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                msg = xhr.responseJSON.message;
                            }
                            alert(msg);
                        }
                    });
                    return false;
                });
            }
        });
    </script>
@endsection
