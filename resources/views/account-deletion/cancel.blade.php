<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ __('Cancel account deletion') }}</title></head>
<body>
    <h1>{{ __('Cancel account deletion') }}</h1>
    <p>{{ __('Canceling restores access to your Audiobook Librarian account.') }}</p>
    <form method="POST" action="{{ url('/account-deletion/cancel/' . $token) }}">
        @csrf
        <button type="submit">{{ __('Cancel deletion') }}</button>
    </form>
</body>
</html>
