@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Admin Dashboard</h1>

        <div class="list-group">
            <a href="{{ route('admin.genres.index') }}" class="list-group-item list-group-item-action">Manage Genres</a>
            <a href="{{ route('admin.authors.index') }}" class="list-group-item list-group-item-action">Manage Authors</a>
            <a href="{{ route('admin.books.index') }}" class="list-group-item list-group-item-action">Manage Books</a>
            <a href="{{ route('admin.messages.index') }}" class="list-group-item list-group-item-action">View Messages</a>
            <a href="{{ route('admin.account_requests.index') }}" class="list-group-item list-group-item-action">Account
                Requests</a>
            <a href="{{ url('/') }}" class="list-group-item list-group-item-action">Public View</a>
        </div>
    </div>
@endsection
