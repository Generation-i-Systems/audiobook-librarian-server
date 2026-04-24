@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex align-items-center justify-content-between mb-4">
        @php
            $initial = strtoupper(substr($user['name'] ?? '?', 0, 1));
            $fallbackSvg = 'data:image/svg+xml;charset=UTF-8,%3Csvg%20width%3D%2264%22%20height%3D%2264%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%3E%3Crect%20width%3D%2264%22%20height%3D%2264%22%20rx%3D%2232%22%20ry%3D%2232%22%20fill%3D%22%236c757d%22%2F%3E%3Ctext%20x%3D%2250%25%22%20y%3D%2255%25%22%20font-family%3D%22Arial%2Csans-serif%22%20font-size%3D%2232%22%20fill%3D%22%23fff%22%20text-anchor%3D%22middle%22%20dominant-baseline%3D%22middle%22%3E' . $initial . '%3C%2Ftext%3E%3C%2Fsvg%3E';
        @endphp
        @if(!empty($user['photo_url']))
            <img src="{{ $user['photo_url'] }}"
                 alt="Profile Photo"
                 class="rounded-circle me-3"
                 style="width: 64px; height: 64px; object-fit: cover;"
                 onerror="this.onerror=null; this.src='{{ $fallbackSvg }}';">
        @else
            <img src="{{ $fallbackSvg }}"
                 alt="{{ $initial }}"
                 class="rounded-circle me-3">
        @endif
        <div>
            <h1 class="h3 mb-0">Edit User
                @if(($user['role'] ?? '') === 'unverified')
                    <span class="badge bg-warning text-dark">Unverified</span>
                @else
                    <span class="badge bg-success">Verified</span>
                @endif

                @if(!empty($user['google_id']))
                    <span class="badge bg-info">
                        <i class="fab fa-google"></i> Google Sign-In
                    </span>
                @endif
            </h1>
            <div class="text-muted">
                {{ $user['email'] ?? '' }}
                @if(!empty($user['google_id']))
                    <span class="ms-2" title="Google ID">
                        <i class="fab fa-google text-info"></i> {{ $user['google_id'] }}
                    </span>
                @endif
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.events.timeline', $user['id']) }}" class="btn btn-outline-secondary btn-sm">
                Event Timeline
            </a>
            <a href="{{ route('admin.books.positions', $user['id']) }}" class="btn btn-outline-secondary btn-sm">
                Books in Progress
            </a>
            <a href="{{ route('admin.users.show', $user['id']) }}" class="btn btn-outline-secondary btn-sm">
                View Profile
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    <form action="{{ route('admin.users.update', $user['id'] ?? '') }}" method="POST">
        @csrf
        @method('PUT')
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Profile Information</h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="name" class="form-label">Name</label>
                    <input type="text" name="name" id="name" class="form-control" required value="{{ old('name', $user['name'] ?? '') }}">
                </div>
        <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <input type="text" name="username" id="username" class="form-control" required value="{{ old('username', $user['username'] ?? '') }}">
        </div>
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" name="email" id="email" class="form-control" required value="{{ old('email', $user['email'] ?? '') }}">
        </div>
        <div class="mb-3">
            <label for="role" class="form-label">Role</label>
            <select name="role" id="role" class="form-control" required>
                <option value="unverified" {{ old('role', $user['role'] ?? '') == 'unverified' ? 'selected' : '' }}>Unverified</option>
                <option value="user" {{ old('role', $user['role'] ?? '') == 'user' ? 'selected' : '' }}>User (Player Only)</option>
                <option value="library-user" {{ old('role', $user['role'] ?? '') == 'library-user' ? 'selected' : '' }}>Library User (Local Books)</option>
                <option value="librivox-user" {{ old('role', $user['role'] ?? '') == 'librivox-user' ? 'selected' : '' }}>LibriVox User (LibriVox Books)</option>
                <option value="hybrid-user" {{ old('role', $user['role'] ?? '') == 'hybrid-user' ? 'selected' : '' }}>Hybrid User (Local + LibriVox)</option>
                <option value="admin" {{ old('role', $user['role'] ?? '') == 'admin' ? 'selected' : '' }}>Admin</option>
                <option value="super-admin" {{ old('role', $user['role'] ?? '') == 'super-admin' ? 'selected' : '' }}>Super Admin</option>
            </select>
        </div>

        @if(($user['role'] ?? '') === 'unverified')
            <div class="alert alert-warning">
                <p class="mb-2">This user is not yet verified. Choose their access type:</p>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-outline-secondary btn-sm verify-role-btn" data-role="user">
                        <i class="fas fa-play"></i> Player Only
                    </button>
                    <button type="button" class="btn btn-success btn-sm verify-role-btn" data-role="library-user">
                        <i class="fas fa-book"></i> Library User
                    </button>
                    <button type="button" class="btn btn-info btn-sm verify-role-btn" data-role="librivox-user">
                        <i class="fas fa-microphone"></i> LibriVox User
                    </button>
                    <button type="button" class="btn btn-primary btn-sm verify-role-btn" data-role="hybrid-user">
                        <i class="fas fa-layer-group"></i> Hybrid User
                    </button>
                </div>
            </div>
        @endif
        <div class="mb-3">
            <label for="password" class="form-label">Password (leave blank to keep current)</label>
            <input type="password" name="password" id="password" class="form-control">
        </div>
        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirm Password</label>
            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control">
        </div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Update User
                    </button>
                    <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">
                        <i class="fas fa-times me-1"></i> Cancel
                    </a>
                </div>
                @if(($user['role'] ?? '') !== 'unverified')
                    <div class="text-muted">
                        <i class="fas fa-check-circle text-success"></i>
                        Account verified on {{ !empty($user['email_verified_at']) ? \Carbon\Carbon::parse($user['email_verified_at'])->format('M j, Y') : 'N/A' }}
                    </div>
                @endif
            </div>
        </div>
    </form>
    @if(($user['role'] ?? '') === 'unverified')
        <form id="verify-user-form" action="{{ route('admin.users.verify', $user['id']) }}" method="POST" class="d-none">
            @csrf
            <input type="hidden" name="role" id="verify-role-input" value="user">
        </form>
        <script>
            document.querySelectorAll('.verify-role-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    document.getElementById('verify-role-input').value = this.dataset.role;
                    document.getElementById('verify-user-form').submit();
                });
            });
        </script>
    @endif
    @if(isset($activityData))
        @include('partials.activity-summary', ['activityData' => $activityData])
    @endif
</div>
@endsection
