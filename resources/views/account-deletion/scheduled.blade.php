<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ __('Account deletion scheduled') }}</title></head>
<body>
    <h1>{{ __('Account deletion scheduled') }}</h1>
    <p>{{ session('status') ?? 'Your account has been scheduled for deletion.' }}</p>
</body>
</html>
