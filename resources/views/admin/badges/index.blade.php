@extends('layouts.app')

@section('content')
<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1>All Badges</h1>
        <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to Users
        </a>
    </div>

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
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($badges as $badge)
                                <tr>
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
