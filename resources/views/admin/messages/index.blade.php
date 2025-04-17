@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Messages</h1>

        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        <h2>Send Push Notification</h2>
        <form action="{{ route('admin.sendNotification') }}" method="POST">
            @csrf
            <div class="form-group">
                <label for="message">Message:</label>
                <textarea class="form-control" id="message" name="message" rows="3" required></textarea>
            </div>
            <div class="form-group">
                <label for="user_id">Send to Specific User (Optional):</label>
                <select class="form-control" id="user_id" name="user_id">
                    <option value="">All Users</option>
                    @foreach($users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Send Notification</button>
        </form>

        <hr>
        <h2>Users requesting Admin Permissions</h2>
        <ul class="list-group">
            @foreach($admin_permissions as $user)
                @if($user->admin_permissions == true)
                    <li class="list-group-item">
                        User <strong>{{ $user->name }}</strong> ({{ $user->email }}) is requesting Admin Permissions.
                    </li>
                @endif
            @endforeach
        </ul>

        <hr>

        <h2>New Messages</h2>
        <ul class="list-group">
            @foreach($messages as $message)
                @if ($message->acknowledged_at == null)
                    <li class="list-group-item">
                        <strong>
                            @if($message->user)
                                {{ $message->user->name }} ({{ $message->user->email }}):
                            @else
                                Mobile App User:
                            @endif
                        </strong>
                        {{ $message->content }}
                        <small class="text-muted">
                            {{ $message->created_at->diffForHumans() }}
                        </small>

                        @if($message->is_from_admin == null)
                            <form action="{{ route('admin.messages.acknowledge', $message) }}" method="POST" style="display:inline;">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-success">Acknowledge</button>
                            </form>
                        @endif
                    </li>
                @endif
            @endforeach
        </ul>

        <hr>
        <h2>Old Messages</h2>
        <ul class="list-group">
            @foreach($messages as $message)
                @if ($message->acknowledged_at != null)
                    <li class="list-group-item">
                        <strong>
                            @if($message->user)
                                {{ $message->user->name }} ({{ $message->user->email }}):
                            @else
                                Mobile App User:
                            @endif
                        </strong>
                        {{ $message->content }}
                        <small class="text-muted">
                            {{ $message->created_at->diffForHumans() }}
                        </small>

                    </li>
                @endif
            @endforeach
        </ul>
    </div>
@endsection
