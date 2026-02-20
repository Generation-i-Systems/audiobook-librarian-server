@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex align-items-center mb-4 justify-content-between">
        <div class="d-flex align-items-center">
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
                <h1 class="h3 mb-0">{{ $user['name'] ?? 'Unknown User' }}
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
                    {{ $user['email'] ?? '' }} ({{ $user['username'] ?? '' }})
                </div>
            </div>
        </div>
        <div>
            @if(auth()->id() === ($user['id'] ?? null))
                <a href="{{ route('profile.index') }}" class="btn btn-primary">
                    <i class="fas fa-edit me-1"></i> Edit Profile
                </a>
            @elseif(auth()->user()->role === 'admin' || auth()->user()->role === 'super-admin')
                <a href="{{ route('admin.users.edit', $user['id']) }}" class="btn btn-warning">
                    <i class="fas fa-edit me-1"></i> Edit User
                </a>
            @endif
        </div>
    </div>

    <div class="row">
        <!-- Profile Info Card -->
        <div class="col-md-3 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0">Profile Info</h5>
                </div>
                <div class="card-body">
                    <p><strong>Role:</strong> {{ ucfirst($user['role'] ?? 'User') }}</p>
                    <p><strong>Joined:</strong> {{ !empty($user['created_at']) ? \Carbon\Carbon::parse($user['created_at'])->format('M j, Y') : 'N/A' }}</p>
                    <p><strong>Last Active:</strong> {{ !empty($user['last_active_at']) ? \Carbon\Carbon::parse($user['last_active_at'])->diffForHumans() : 'N/A' }}</p>
                    <div class="d-grid gap-2 mt-3">
                        <a href="{{ route('admin.events.timeline', $user['id']) }}" class="btn btn-outline-secondary btn-sm">
                            Event Timeline
                        </a>
                        <a href="{{ route('admin.books.positions', $user['id']) }}" class="btn btn-outline-secondary btn-sm">
                            Books in Progress
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Badge Tips and Activity Sections -->
        <div class="col-md-9">
            @include('partials.activity-summary', ['activityData' => $activityData, 'userId' => $user['id']])
        </div>
    </div>
</div>
@endsection
