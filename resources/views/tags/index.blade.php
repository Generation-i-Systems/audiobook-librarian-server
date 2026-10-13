@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>{{ __('System Tags') }}</h1>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('Close') }}"></button>
            </div>
        @endif

        @if (session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('Close') }}"></button>
            </div>
        @endif

        <p class="text-muted">Only system-scope tags are managed here. Group and user tags are private to their
            owners and are not shown in this admin list.</p>

        <table class="table table-striped">
            <thead>
                <tr>
                    <th>{{ __('Tag') }}</th>
                    <th>{{ __('Books') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($tags as $tag)
                    <tr>
                        <td>{{ $tag['name'] }}</td>
                        <td>{{ $tag['count'] }}</td>
                        <td>
                            @can('manage-tags')
                                <a href="{{ route('tags.edit', $tag['name']) }}" class="btn btn-sm btn-outline-primary">{{ __('Rename') }}</a>
                                <form action="{{ route('tags.destroy', $tag['name']) }}" method="POST" class="d-inline"
                                    onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Delete this tag from all books?')) }});">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Delete') }}</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-muted">{{ __('No system tags yet. Add one from a book\'s page.') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
