@extends('layouts.app')

@section('content')
<div class="container">
    <h1>{{ __('Message Detail') }}</h1>
    <div class="card mb-4">
        <div class="card-header">
            <strong>{{ __('Subject:') }}</strong> {{ $message->subject }}
        </div>
        <div class="card-body">
            <p><strong>{{ __('From:') }}</strong> 
                @if($message->fromUser)
                    {{ $message->fromUser->name }} ({{ $message->fromUser->email }})
                @else
                    System
                @endif
            </p>
            <p><strong>{{ __('Received:') }}</strong> {{ $message->created_at->format('Y-m-d H:i') }}</p>
            <hr>
            <div style="white-space: pre-wrap;">{{ $message->body }}</div>
        </div>
        <div class="card-footer">
            @if(!$message->is_read)
                <form action="{{ route('admin.messages.markAsRead', $message->id) }}" method="POST" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-success">{{ __('Mark as Read') }}</button>
                </form>
            @else
                <span class="badge bg-light text-secondary">{{ __('Read') }}</span>
            @endif
            <a href="{{ route('admin.messages.index') }}" class="btn btn-link">{{ __('Back to Inbox') }}</a>
        </div>
    </div>
</div>
@endsection
