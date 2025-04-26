@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Compose New Message</h1>
    <form action="{{ route('admin.messages.store') }}" method="POST">
        @csrf
        <div class="mb-3">
            <label for="to_user_id" class="form-label">Recipient</label>
            <select name="to_user_id" id="to_user_id" class="form-select">
                <option value="">-- Select User (optional, leave blank for broadcast/system message) --</option>
                @foreach($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label for="subject" class="form-label">Subject</label>
            <input type="text" name="subject" id="subject" class="form-control" required maxlength="255">
        </div>
        <div class="mb-3">
            <label for="body" class="form-label">Message</label>
            <textarea name="body" id="body" class="form-control" rows="6" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Send Message</button>
        <a href="{{ route('admin.messages.index') }}" class="btn btn-secondary">Cancel</a>
    </form>
</div>
@endsection
