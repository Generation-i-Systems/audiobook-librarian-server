<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Account deletion cancellation</title></head>
<body>
    <h1>Account deletion cancellation</h1>
    <p>{{ session('status') ?? session('error') ?? 'This cancellation link is invalid or has expired.' }}</p>
</body>
</html>
