<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Account deletion scheduled</title></head>
<body>
    <p>Hi{{ $recipientName ? ' ' . $recipientName : '' }},</p>
    <p>Your Audiobook Librarian account is scheduled for permanent deletion on {{ $scheduledFor }}.</p>
    <p>Your account is no longer accessible. If this was not you, or you changed your mind, cancel deletion before that date:</p>
    <p><a href="{{ $cancellationUrl }}">Cancel account deletion</a></p>
</body>
</html>
