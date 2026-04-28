<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Password Reset — {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 40px 32px;
            max-width: 420px;
            width: 100%;
            text-align: center;
            box-shadow: 0 2px 16px rgba(0,0,0,0.08);
        }
        .icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: #e8f5e9;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 32px;
        }
        h1 { font-size: 22px; font-weight: 600; color: #1a1a1a; margin-bottom: 12px; }
        p { font-size: 15px; color: #555; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">✓</div>
        <h1>Password Reset</h1>
        <p>Your password has been reset successfully. You can now sign in to the app with your new password.</p>
    </div>
</body>
</html>
