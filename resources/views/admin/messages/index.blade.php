@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Messages</h1>

        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        <div class="mb-4">
            <a href="{{ route('admin.messages.index') }}" class="btn btn-primary">Inbox</a>
            <a href="{{ route('admin.messages.create') }}" class="btn btn-secondary">New Message</a>
        </div>

        <table class="table table-hover">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Subject</th>
                    <th>From</th>
                    <th>Received</th>
                </tr>
            </thead>
            <tbody>
                @forelse($messages as $message)
                    <tr class="{{ $message->is_read ? '' : 'table-info' }}">
                        <td>
                            @if(!$message->is_read)
                                <span class="badge bg-warning text-dark">New</span>
                            @else
                                <span class="badge bg-light text-secondary">Read</span>
                            @endif
                        </td>
                        <td>
                            <a href="{{ route('admin.messages.show', $message->id) }}">
                                {{ $message->subject }}
                            </a>
                        </td>
                        <td>
                            @if($message->fromUser)
                                {{ $message->fromUser->name }} ({{ $message->fromUser->email }})
                            @else
                                System
                            @endif
                        </td>
                        <td>{{ $message->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4">No messages.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
