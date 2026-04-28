<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password — {{ config('app.name') }}</title>
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
            box-shadow: 0 2px 16px rgba(0,0,0,0.08);
        }
        h1 { font-size: 22px; font-weight: 600; color: #1a1a1a; margin-bottom: 8px; }
        .subtitle { font-size: 14px; color: #666; margin-bottom: 28px; }
        label { display: block; font-size: 13px; font-weight: 500; color: #333; margin-bottom: 6px; }
        input {
            display: block;
            width: 100%;
            padding: 10px 14px;
            font-size: 15px;
            border: 1px solid #d0d0d0;
            border-radius: 8px;
            margin-bottom: 4px;
            outline: none;
            transition: border-color 0.15s;
        }
        input:focus { border-color: #6750a4; }
        .error { font-size: 12px; color: #c62828; margin-bottom: 12px; }
        .field { margin-bottom: 20px; }
        button {
            display: block;
            width: 100%;
            padding: 12px;
            background: #6750a4;
            color: white;
            font-size: 15px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 8px;
        }
        button:hover { background: #5a4494; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Reset Password</h1>
        <p class="subtitle">Choose a new password for your account.</p>

        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="field">
                <label for="email">Email Address</label>
                <input id="email" type="email" name="email"
                       value="{{ $email ?? old('email') }}"
                       required autocomplete="email" autofocus>
                @error('email')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="password">New Password</label>
                <input id="password" type="password" name="password"
                       required autocomplete="new-password" minlength="8">
                @error('password')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <div class="field">
                <label for="password-confirm">Confirm New Password</label>
                <input id="password-confirm" type="password" name="password_confirmation"
                       required autocomplete="new-password">
            </div>

            <button type="submit">Reset Password</button>
        </form>
    </div>
</body>
</html>
