@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>User Management</h1>
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif
        @if(session('info'))
            <div class="alert alert-info">{{ session('info') }}</div>
        @endif

        <div class="mb-3 d-flex gap-2">
            <a href="{{ route('admin.users.create') }}" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add User
            </a>
            <a href="{{ route('admin.badges.index') }}" class="btn btn-info text-white">
                <i class="fas fa-medal"></i> All Badges
            </a>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th>ID</th>
                                <th>Photo</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Role</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($users as $user)
                                <tr style="cursor: pointer;"
                                    onclick="if(event.target.closest('a,button,form')) return; window.location='{{ route('admin.users.show', $user['id']) }}'">
                                    <td>{{ $user['id'] }}</td>
                                    <td>
                                        @php
                                            $initial = strtoupper(substr($user['name'] ?? '?', 0, 1));
                                            $fallbackSvg = 'data:image/svg+xml;charset=UTF-8,%3Csvg%20width%3D%2240%22%20height%3D%2240%22%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%3E%3Crect%20width%3D%2240%22%20height%3D%2240%22%20rx%3D%2220%22%20ry%3D%2220%22%20fill%3D%22%236c757d%22%2F%3E%3Ctext%20x%3D%2250%25%22%20y%3D%2255%25%22%20font-family%3D%22Arial%2Csans-serif%22%20font-size%3D%2220%22%20fill%3D%22%23fff%22%20text-anchor%3D%22middle%22%20dominant-baseline%3D%22middle%22%3E' . $initial . '%3C%2Ftext%3E%3C%2Fsvg%3E';
                                        @endphp
                                        @if(!empty($user['photo_url']))
                                            <img src="{{ $user['photo_url'] }}" alt="Profile Photo" class="rounded-circle"
                                                style="width: 40px; height: 40px; object-fit: cover;"
                                                onerror="this.onerror=null; this.src='{{ $fallbackSvg }}';">
                                        @else
                                            <img src="{{ $fallbackSvg }}" alt="{{ $initial }}" class="rounded-circle"
                                                style="width: 40px; height: 40px;">
                                        @endif
                                    </td>
                                    <td>
                                        <div class="fw-bold">
                                            <a href="{{ route('admin.users.show', $user['id']) }}" class="text-decoration-none text-dark">
                                                {{ $user['name'] ?? 'N/A' }}
                                            </a>
                                        </div>
                                        <small class="text-muted">{{ $user['username'] ?? '' }}</small>
                                    </td>
                                    <td>{{ $user['email'] ?? 'N/A' }}</td>
                                    <td>
                                        @if(($user['role'] ?? '') === 'unverified')
                                            <span class="badge bg-warning text-dark">Unverified</span>
                                        @else
                                            <span class="badge bg-success">Verified</span>
                                        @endif
                                    </td>
                                    <td>{{ ucfirst($user['role'] ?? 'user') }}</td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            @if(($user['role'] ?? '') === 'unverified')
                                                <button type="button" class="btn btn-success btn-sm" title="Verify User"
                                                    data-bs-toggle="modal" data-bs-target="#verifyModal"
                                                    data-user-id="{{ $user['id'] }}"
                                                    data-user-name="{{ $user['name'] ?? $user['email'] }}">
                                                    <i class="fas fa-check"></i> Verify
                                                </button>
                                            @endif

                                            <a href="{{ route('admin.events.timeline', $user['id']) }}"
                                                class="btn btn-outline-secondary btn-sm" title="Event Timeline">
                                                <i class="fas fa-stream"></i>
                                            </a>

                                            <a href="{{ route('admin.books.positions', $user['id']) }}"
                                                class="btn btn-outline-secondary btn-sm" title="Books in Progress">
                                                <i class="fas fa-book-open"></i>
                                            </a>

                                            <a href="{{ route('admin.users.edit', $user['id']) }}"
                                                class="btn btn-warning btn-sm" title="Edit User">
                                                <i class="fas fa-edit"></i>
                                            </a>

                                            <form action="{{ route('admin.users.destroy', $user['id']) }}" method="POST"
                                                class="d-inline"
                                                onsubmit="return confirm('Are you sure you want to delete user &quot;{{ $user['name'] ?? $user['email'] }}&quot;?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-danger btn-sm" title="Delete User">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center">No users found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Verify User Modal -->
        <div class="modal fade" id="verifyModal" tabindex="-1" aria-labelledby="verifyModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="verifyModalLabel">Verify User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>What type of access should <strong id="verifyUserName"></strong> have?</p>
                    </div>
                    <div class="modal-footer flex-wrap gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <form id="verifyPlayerForm" method="POST" class="d-inline">
                            @csrf
                            <input type="hidden" name="role" value="user">
                            <button type="submit" class="btn btn-outline-secondary">
                                <i class="fas fa-play"></i> Player Only
                            </button>
                        </form>
                        <form id="verifyLibraryForm" method="POST" class="d-inline">
                            @csrf
                            <input type="hidden" name="role" value="library-user">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-book"></i> Library User
                            </button>
                        </form>
                        <form id="verifyLibrivoxForm" method="POST" class="d-inline">
                            @csrf
                            <input type="hidden" name="role" value="librivox-user">
                            <button type="submit" class="btn btn-info">
                                <i class="fas fa-microphone"></i> LibriVox User
                            </button>
                        </form>
                        <form id="verifyHybridForm" method="POST" class="d-inline">
                            @csrf
                            <input type="hidden" name="role" value="hybrid-user">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-layer-group"></i> Hybrid User
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('verifyModal').addEventListener('show.bs.modal', function(event) {
            const userId = event.relatedTarget.getAttribute('data-user-id');
            const userName = event.relatedTarget.getAttribute('data-user-name');
            const baseUrl = '/admin/users/' + userId + '/verify';

            document.getElementById('verifyUserName').textContent = userName;

            ['verifyPlayerForm', 'verifyLibraryForm', 'verifyLibrivoxForm', 'verifyHybridForm'].forEach(function(id) {
                document.getElementById(id).setAttribute('action', baseUrl);
            });
        });
    </script>
@endsection
