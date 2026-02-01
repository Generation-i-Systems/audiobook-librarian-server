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
