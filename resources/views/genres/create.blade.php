@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>{{ __('Create New Genre') }}</h1>

        <form action="{{ route('genres.store') }}" method="POST">
            @csrf

            <div class="form-group">
                <label for="name">{{ __('Name:') }}</label>
                <input type="text" class="form-control" id="name" name="name" value="{{ old('name') }}" required>
            </div>

            <div class="form-group mt-3">
                <label for="emoji">{{ __('Emoji:') }}</label>
                <input type="text" class="form-control" id="emoji" name="emoji" value="{{ old('emoji') }}" maxlength="16">
            </div>

            <div class="form-group mt-3">
                <label for="icon_path">{{ __('Icon path:') }}</label>
                <input type="text" class="form-control" id="icon_path" name="icon_path" value="{{ old('icon_path') }}" placeholder="/images/genres/fantasy.svg">
            </div>

            <button type="submit" class="btn btn-primary mt-3">{{ __('Create') }}</button>
            <a href="{{ route('genres.index') }}" class="btn btn-secondary">{{ __('Cancel') }}</a>
        </form>
    </div>
@endsection
