<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Modal')</title>
    @yield('styles')
</head>
<body style="background:transparent;">
    @yield('content')
    @yield('scripts')
</body>
</html>
