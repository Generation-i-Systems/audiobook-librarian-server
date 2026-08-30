@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>All Badges</h1>
        <div>
            <a href="{{ route('admin.badges.create') }}" class="btn btn-primary">
                <i class="fas fa-plus me-1"></i> New Badge
            </a>
            <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back to Users
            </a>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @foreach($badgesByCategory as $category => $badges)
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">{{ $category }}</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th style="width: 80px;">Icon</th>
                                <th style="width: 20%;">Name</th>
                                <th style="width: 15%;">Tier</th>
                                <th>Description & Criteria</th>
                                <th style="width: 10%;">Points</th>
                                <th style="width: 15%;">Status</th>
                                <th style="width: 15%;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($badges as $badge)
                                <tr class="{{ $badge->is_active ? '' : 'opacity-50' }}">
                                    <td class="text-center">
                                        @if($badge->icon_path)
                                            <img src="{{ $badge->icon_path }}" alt="{{ $badge->name }}" style="width: 48px; height: 48px;">
                                        @elseif($badge->icon)
                                             <span style="font-size: 2.5rem;">{{ $badge->icon }}</span>
                                        @else
                                            <i class="fas fa-medal fa-3x text-secondary"></i>
                                        @endif
                                    </td>
                                    <td>
                                        <strong>{{ $badge->name }}</strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-{{ $badge->tier === 'gold' ? 'warning' : ($badge->tier === 'silver' ? 'secondary' : ($badge->tier === 'bronze' ? 'brown' : ($badge->tier === 'platinum' ? 'info' : ($badge->tier === 'diamond' ? 'primary' : 'light text-dark')))) }}">
                                            {{ ucfirst($badge->tier) }}
                                        </span>
                                    </td>
                                    <td>
                                        <p class="mb-1">{{ $badge->description }}</p>
                                        <small class="text-muted">
                                        <small class="text-muted">
                                            <strong>Criteria:</strong>
                                            @if(is_array($badge->criteria))
                                                <ul class="mb-0 ps-3 list-unstyled">
                                                    @foreach($badge->criteria as $key => $value)
                                                        <li>
                                                            <span class="fw-semibold">{{ ucwords(str_replace('_', ' ', $key)) }}:</span>
                                                            {{ is_array($value) ? json_encode($value) : $value }}
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @else
                                                {{ $badge->criteria }}
                                            @endif
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge bg-success rounded-pill">{{ $badge->points }} XP</span>
                                    </td>
                                    <td>
                                        @if($badge->is_active)
                                            <span class="badge bg-success">Active</span>
                                        @else
                                            <span class="badge bg-secondary">Inactive</span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-1">
                                            <a href="{{ route('admin.badges.edit', $badge) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                            @if($badge->is_active)
                                                <form action="{{ route('admin.badges.destroy', $badge) }}" method="POST" onsubmit="return confirm('Deactivate this badge? It will stop being awarded, but earned history is kept.');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-warning w-100">Deactivate</button>
                                                </form>
                                            @else
                                                <form action="{{ route('admin.badges.activate', $badge) }}" method="POST">
                                                    @csrf
                                                    <button type="submit" class="btn btn-sm btn-outline-success w-100">Activate</button>
                                                </form>
                                                @if($badge->can_force_delete)
                                                    <form action="{{ route('admin.badges.forceDestroy', $badge) }}" method="POST" onsubmit="return confirm('Permanently delete this badge? This cannot be undone.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn btn-sm btn-outline-danger w-100">Delete forever</button>
                                                    </form>
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endsection
