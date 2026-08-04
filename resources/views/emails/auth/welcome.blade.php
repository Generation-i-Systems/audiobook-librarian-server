@php
    $appName = config('app.name', 'Librarian');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Welcome to {{ $appName }}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background:#f6f7f9; margin:0; padding:0;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f6f7f9; padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:520px; background:#ffffff; border-radius:12px; padding:32px;">
                    <tr>
                        <td>
                            <h1 style="margin:0 0 12px 0; font-size:20px; color:#1a1a1a;">Welcome to {{ $appName }}</h1>
                            <p style="margin:0 0 20px 0; font-size:14px; color:#444; line-height:1.5;">
                                @if(!empty($recipientName))
                                    Hi {{ $recipientName }},
                                @else
                                    Hi,
                                @endif
                                your account is ready. Use the code below or click the button to sign in. This code expires in {{ $ttlMinutes }} minutes and can only be used once.
                            </p>

                            <p style="margin:0 0 8px 0; font-size:13px; color:#666;">Your sign-in code:</p>
                            <p style="margin:0 0 24px 0; font-size:32px; letter-spacing:8px; font-weight:700; color:#1a1a1a; font-family: 'SFMono-Regular', Menlo, Consolas, monospace;">{{ $code }}</p>

                            <p style="margin:0 0 24px 0;">
                                <a href="{{ $magicLinkUrl }}" style="display:inline-block; background:#3955ff; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:8px; font-weight:600; font-size:14px;">Sign in with one click</a>
                            </p>

                            <p style="margin:0 0 4px 0; font-size:12px; color:#888;">If the button doesn't work, copy this URL into your browser:</p>
                            <p style="margin:0 0 24px 0; font-size:12px; color:#3955ff; word-break:break-all;">{{ $magicLinkUrl }}</p>

                            <hr style="border:none; border-top:1px solid #eee; margin:24px 0;">

                            <h2 style="margin:0 0 8px 0; font-size:16px; color:#1a1a1a;">Get the app</h2>
                            <p style="margin:0 0 16px 0; font-size:14px; color:#444; line-height:1.5;">
                                Install {{ $appName }} on your phone or tablet:
                            </p>
                            <p style="margin:0 0 24px 0;">
                                <a href="{{ $androidStoreUrl }}" style="display:inline-block; background:#1a1a1a; color:#ffffff; text-decoration:none; padding:10px 16px; border-radius:8px; font-weight:600; font-size:13px; margin-right:8px;">Get it on Google Play</a>
                                <a href="{{ $iosStoreUrl }}" style="display:inline-block; background:#1a1a1a; color:#ffffff; text-decoration:none; padding:10px 16px; border-radius:8px; font-weight:600; font-size:13px;">Download on the App Store</a>
                            </p>

                            <h2 style="margin:0 0 8px 0; font-size:16px; color:#1a1a1a;">Connect it to this server</h2>
                            <p style="margin:0 0 16px 0; font-size:14px; color:#444; line-height:1.5;">
                                Open the app, tap the button below (or visit it on this device to scan its QR code), then sign in with the code above. You can also connect any time later from the app's first-time setup screens or from Settings &rarr; Connected Services.
                            </p>
                            <p style="margin:0 0 24px 0;">
                                <a href="{{ $connectUrl }}" style="display:inline-block; background:#3955ff; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:8px; font-weight:600; font-size:14px;">Connect the app to this server</a>
                            </p>

                            <hr style="border:none; border-top:1px solid #eee; margin:24px 0;">
                            <p style="margin:0; font-size:12px; color:#888; line-height:1.5;">
                                Didn't request this account? You can safely ignore this email &mdash; no changes will be made.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
