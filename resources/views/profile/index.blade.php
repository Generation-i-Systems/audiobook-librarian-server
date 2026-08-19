@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>User Profile</h1>

        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        @auth
            <p>
                <strong>Role:</strong>
                @if (Auth::user()->role === 'admin')
                    Admin
                @else
                    Regular User
                @endif
            </p>
            @if (Auth::user()->role === 'admin')
                <a class="nav-link" href="{{ route('admin.books.index') }}">{{ __('Admin Section') }}</a>
            @endif


            <form action="{{ route('profile.update') }}" method="POST">
                @csrf
                @method('PUT')

                <div class="form-group">
                    <label for="name">Name:</label>
                    <input type="text" class="form-control" id="name" name="name" value="{{ Auth::user()->name }}" required>
                </div>

                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" class="form-control" id="email" name="email" value="{{ Auth::user()->email }}" required>
                </div>

                <button type="submit" class="btn btn-primary">Update Profile</button>
            </form>

            <hr>

            <h2>Change Password</h2>
            <form action="{{ route('profile.changePassword') }}" method="POST">
                @csrf
                @method('PUT')

                <div class="form-group">
                    <label for="current_password">Current Password:</label>
                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                    @error('current_password')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password">New Password:</label>
                    <input type="password" class="form-control" id="password" name="password" required>
                    @error('password')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password_confirmation">Confirm New Password:</label>
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation"
                        required>
                </div>

                <button type="submit" class="btn btn-primary">Change Password</button>
            </form>

            <hr>

            <h2>Delete Account</h2>
            <p>
                This permanently deletes your account and library data. You'll have
                {{ \App\Services\AccountDeletionService::RETENTION_DAYS }} days to cancel via a
                link sent to your email before it's erased for good.
            </p>
            <form action="{{ route('profile.destroy') }}" method="POST"
                onsubmit="return confirm('Delete your account? This cannot be undone once the cancellation window passes.');">
                @csrf
                @method('DELETE')

                <div class="form-group">
                    <label for="confirm_email">Type your account email to confirm:</label>
                    <input type="email" class="form-control" id="confirm_email" name="confirm_email" required>
                    @error('confirm_email')
                        <span class="text-danger">{{ $message }}</span>
                    @enderror
                </div>

                <button type="submit" class="btn btn-danger">Delete My Account</button>
            </form>
    @else
            <div class="alert alert-warning">
                <p>You must be logged in to view your profile.</p>
                <a href="{{ route('login') }}" class="btn btn-primary">Login</a>
            </div>
        @endauth

        @if(isset($activityData))
            <hr class="my-5">

            @include('partials.activity-summary', ['activityData' => $activityData])
        @endif
    </div>
@endsection
