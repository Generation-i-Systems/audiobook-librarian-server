@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>{{ __('User Profile') }}</h1>

        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        @auth
            <p>
                <strong>{{ __('Role:') }}</strong>
                @if (Auth::user()->role === 'admin')
                    Admin
                @else
                    {{ __('Regular User') }}
                @endif
            </p>
            @if (Auth::user()->role === 'admin')
                <a class="nav-link" href="{{ route('admin.books.index') }}">{{ __('Admin Section') }}</a>
            @endif


            <form action="{{ route('profile.update') }}" method="POST">
                @csrf
                @method('PUT')

                <div class="form-group">
                    <label for="name">{{ __('Name:') }}</label>
                    <input type="text" class="form-control" id="name" name="name" value="{{ Auth::user()->name }}" required>
                </div>

                <div class="form-group">
                    <label for="email">{{ __('Email:') }}</label>
                    <input type="email" class="form-control" id="email" name="email" value="{{ Auth::user()->email }}" required>
                </div>

                <button type="submit" class="btn btn-primary">{{ __('Update Profile') }}</button>
            </form>

            <hr>

            <h2>{{ __('Change Password') }}</h2>
            <form action="{{ route('profile.changePassword') }}" method="POST">
                @csrf
                @method('PUT')

                <div class="form-group">
                    <label for="current_password">{{ __('Current Password:') }}</label>
                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                    @error('current_password')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password">{{ __('New Password:') }}</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                    @error('password')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password_confirmation">{{ __('Confirm New Password:') }}</label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                        required>
                </div>

                <button type="submit" class="btn btn-primary">{{ __('Change Password') }}</button>
            </form>

            <hr>

            <h2>{{ __('API / Service Tokens') }}</h2>
            <p class="text-muted">
                Use these to connect personal tools (like the ABB bridge browser extension) to
                this server's API. Each token acts as you, so treat it like a password.
            </p>

            @if(session('new_token'))
                <div class="alert alert-success">
                    <p><strong>New token "{{ session('new_token_name') }}" created.</strong>
                    Copy it now &mdash; it will not be shown again:</p>
                    <code style="user-select: all; word-break: break-all;">{{ session('new_token') }}</code>
                </div>
            @endif

            @if($apiTokens->isNotEmpty())
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Name') }}</th>
                            <th>{{ __('Created') }}</th>
                            <th>{{ __('Last Used') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($apiTokens as $apiToken)
                            <tr>
                                <td>{{ $apiToken->name }}</td>
                                <td>{{ $apiToken->created_at->format('Y-m-d') }}</td>
                                <td>{{ $apiToken->last_used_at?->format('Y-m-d H:i') ?? 'Never' }}</td>
                                <td>
                                    <form action="{{ route('profile.tokens.destroy', $apiToken) }}" method="POST"
                                        onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Revoke this token? Anything using it will stop working immediately.')) }});">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger">{{ __('Revoke') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <form action="{{ route('profile.tokens.store') }}" method="POST" class="form-inline">
                @csrf
                <div class="form-group">
                    <label for="token_name">{{ __('Token name:') }}</label>
                    <input type="text" class="form-control" id="token_name" name="name" placeholder="{{ __('ABB Bridge') }}" required>
                </div>
                <button type="submit" class="btn btn-primary">{{ __('Create Token') }}</button>
            </form>

            <hr>

            <h2>{{ __('Delete Account') }}</h2>
            <p>
                This permanently deletes your account and library data. You'll have
                {{ \App\Services\AccountDeletionService::RETENTION_DAYS }} days to cancel via a
                link sent to your email before it's erased for good.
            </p>
            <form action="{{ route('profile.destroy') }}" method="POST"
                onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Delete your account? This cannot be undone once the cancellation window passes.')) }});">
                @csrf
                @method('DELETE')

                <div class="form-group">
                    <label for="confirm_email">{{ __('Type your account email to confirm:') }}</label>
                    <input type="email" class="form-control" id="confirm_email" name="confirm_email" required>
                    @error('confirm_email')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
                </div>

                <button type="submit" class="btn btn-danger">{{ __('Delete My Account') }}</button>
            </form>
    @else
            <div class="alert alert-warning">
                <p>{{ __('You must be logged in to view your profile.') }}</p>
                <a href="{{ route('login') }}" class="btn btn-primary">{{ __('Login') }}</a>
            </div>
        @endauth

        @if(isset($activityData))
            <hr class="my-5">

            @include('partials.activity-summary', ['activityData' => $activityData])
        @endif
    </div>
@endsection
