@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>{{ __('Create New Author') }}</h1>

        <form action="{{ route('authors.store') }}" method="POST">
            @csrf

            <div class="form-group">
                <label for="name">{{ __('Name:') }}</label>
                <input type="text" class="form-control" id="name" name="name" required>
            </div>

            <button type="submit" class="btn btn-primary">{{ __('Create') }}</button>
            <a href="{{ route('authors.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
        </form>
    </div>
@endsection
