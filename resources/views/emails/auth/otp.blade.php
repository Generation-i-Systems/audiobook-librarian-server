@php
    $appName = config('app.name', 'Librarian');
    $isPasswordReset = $isPasswordReset ?? false;
    $title = $isPasswordReset ? 'Reset your password' : 'Sign in to ' . $appName;
    $action = $isPasswordReset ? 'reset your password' : 'sign in';
    $buttonText = $isPasswordReset ? 'Reset password with one click' : 'Sign in with one click';
    $codeLabel = $isPasswordReset ? 'Your password reset code:' : 'Your sign-in code:';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background:#f6f7f9; margin:0; padding:0;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f6f7f9; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:520px; background:#ffffff; border-radius:12px; padding:32px;">
                    <tr>
                        <td>
                            <h1 style="margin:0 0 12px 0; font-size:20px; color:#1a1a1a;">{{ $title }}</h1>
                            <p style="margin:0 0 20px 0; font-size:14px; color:#444; line-height:1.5;">
                                @if(!empty($recipientName))
                                    Hi {{ $recipientName }},
                                @else
                                    Hi,
                                @endif
                                use the code below or click the button to {{ $action }}. This code expires in {{ $ttlMinutes }} minutes and can only be used once.
                            </p>

                            <p style="margin:0 0 8px 0; font-size:13px; color:#666;">{{ $codeLabel }}</p>
                            <p style="margin:0 0 24px 0; font-size:32px; letter-spacing:8px; font-weight:700; color:#1a1a1a; font-family: 'SFMono-Regular', Menlo, Consolas, monospace;">{{ $code }}</p>

                            <p style="margin:0 0 24px 0;">
                                <a href="{{ $magicLinkUrl }}" style="display:inline-block; background:#3955ff; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:8px; font-weight:600; font-size:14px;">{{ $buttonText }}</a>
                            </p>

                            <p style="margin:0 0 4px 0; font-size:12px; color:#888;">If the button doesn't work, copy this URL into your browser:</p>
                            <p style="margin:0 0 24px 0; font-size:12px; color:#3955ff; word-break:break-all;">{{ $magicLinkUrl }}</p>

                            <hr style="border:none; border-top:1px solid #eee; margin:24px 0;">
                            <p style="margin:0; font-size:12px; color:#888; line-height:1.5;">
                                Didn't request this? You can safely ignore this email &mdash; no changes will be made to your account.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
