@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>User Management</h1>
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary mb-3">Add User</a>
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
    <tr>
        <td>{{ $user['id'] ?? '-' }}</td>
        <td>{{ !empty($user['name']) ? $user['name'] : '-' }}</td>
        <td>{{ !empty($user['email']) ? $user['email'] : '-' }}</td>
        <td>{{ !empty($user['role']) ? $user['role'] : '-' }}</td>
        <td>
            <a href="{{ route('admin.users.edit', $user['id'] ?? '') }}" class="btn btn-sm btn-warning">Edit</a>
            <form action="{{ route('admin.users.destroy', $user['id'] ?? '') }}" method="POST" style="display:inline-block">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</button>
            </form>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="5" class="text-center">No users found.</td>
    </tr>
@endforelse
            </tbody>
        </table>
    </div>
@endsection
