@php
    $appName = config('app.name', 'Librarian');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Sign up for {{ $appName }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background:#f6f7f9; margin:0; padding:0;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f6f7f9; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:520px; background:#ffffff; border-radius:12px; padding:32px;">
                    <tr>
                        <td>
                            <h1 style="margin:0 0 12px 0; font-size:20px; color:#1a1a1a;">Sign up for {{ $appName }}</h1>
                            <p style="margin:0 0 16px 0; font-size:14px; color:#444; line-height:1.5;">
                                @if(!empty($recipientName))
                                    Hi {{ $recipientName }},
                                @else
                                    Hi,
                                @endif
                                someone requested a sign-in code for this email, but we don't have an account associated with it.
                            </p>
                            <p style="margin:0 0 24px 0; font-size:14px; color:#444; line-height:1.5;">
                                If you'd like to create an account, click the button below to get started.
                            </p>

                            <p style="margin:0 0 24px 0;">
                                <a href="{{ $registerUrl }}" style="display:inline-block; background:#3955ff; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:8px; font-weight:600; font-size:14px;">Create an account</a>
                            </p>

                            <p style="margin:0 0 24px 0; font-size:13px; color:#888; line-height:1.5;">
                                New accounts require admin approval before full access is granted. You'll receive a notification once your account has been approved.
                            </p>

                            <hr style="border:none; border-top:1px solid #eee; margin:24px 0;">
                            <p style="margin:0; font-size:12px; color:#888; line-height:1.5;">
                                Didn't request this? You can safely ignore this email &mdash; no account will be created and no changes will be made.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
