<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ __('Account deletion cancellation') }}</title></head>
<body>
    <h1>{{ __('Account deletion cancellation') }}</h1>
    <p>{{ session('status') ?? session('error') ?? 'This cancellation link is invalid or has expired.' }}</p>
</body>
</html>
