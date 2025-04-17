@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>User Profile</h1>

        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        @if(Auth::user()->admin_permissions == false)
            <form action="{{ route('profile.requestAdminPermissions') }}" method="POST">
                @csrf
                <div class="form-group">
                    <label for="request_content">Write a description</label>
                    <textarea class="form-control" id="content" name="content" rows="3" required></textarea>
                </div>
                <button type="submit" class="btn btn-warning">Request Admin Permissions</button>
                <small id="request_content" class="form-text text-muted">By requesting permissions, an Admin user will have the
                    ability to review your account and change it accordingly.</small>
            </form>
        @endif

        <p>
            <strong>Role:</strong>
            @if (Auth::user()->role === 'admin')
                Admin
            @else
                Regular User
            @endif
        </p>
        @if (Auth::user()->role === 'admin')
            <a class="nav-link" href="{{ route('admin.index') }}">{{ __('Admin Section') }}</a>
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
    </div>
@endsection
