<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Confirm account deletion</title></head>
<body>
    <p>Hi{{ $recipientName ? ' ' . $recipientName : '' }},</p>
    <p>Use this code in Audiobook Librarian to confirm your account deletion request:</p>
    <p><strong style="font-size: 24px; letter-spacing: 4px;">{{ $verificationCode }}</strong></p>
    <p>This code expires in 10 minutes. If you did not request deletion, you can safely ignore this email.</p>
</body>
</html>
