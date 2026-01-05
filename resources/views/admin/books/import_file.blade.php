@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Import Book from File or Audio</h1>
    <div class="alert alert-info mb-4">
        <strong>Smart Import:</strong> Select a file or directory from configured import locations. Metadata will be extracted (with optional AI enhancement) and the book form will be prefilled for review and completion.
    </div>
    <div id="import-file-browser-root">
        @include('admin.books.import_file_browser')
    </div>
    <div class="mt-4">
        <a href="{{ route('admin.books.index') }}" class="btn btn-secondary">Back to Books</a>
    </div>
</div>
    @vite(['resources/js/admin/books/import_file.js'])
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (window.initImportFileBrowser) {
                window.initImportFileBrowser('#import-file-browser-root');
            }
        });
    </script>
@endsection
