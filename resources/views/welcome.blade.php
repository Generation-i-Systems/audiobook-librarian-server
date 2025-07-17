<?php
use Illuminate\Support\Facades\Route;
?>
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Audiobook Librarian</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,600&display=swap" rel="stylesheet" />

    <!-- Styles -->
    <style>
        body {
            font-family: 'Figtree', sans-serif;
            background-color: #f8f9fa;
            /* Light gray background */
            color: #343a40;
            /* Dark gray text */
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            overflow: hidden;
            /* Hide scrollbars */
        }

        .container {
            text-align: center;
            padding: 3rem;
            background-color: #fff;
            /* White container */
            border-radius: 1rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
        }

        h1 {
            font-size: 4rem;
            font-weight: bold;
            color: #007bff;
            /* Primary blue */
            margin-bottom: 1rem;
        }

        p {
            font-size: 1.5rem;
            color: #6c757d;
            /* Medium gray */
            margin-bottom: 2rem;
        }

        .btn-primary {
            background-color: #007bff;
            border-color: #007bff;
            color: #fff;
            padding: 1rem 2rem;
            font-size: 1.25rem;
            border-radius: 0.5rem;
            text-decoration: none;
            transition: background-color 0.2s ease-in-out;
        }

        .btn-primary:hover {
            background-color: #0056b3;
            border-color: #0056b3;
        }

        .animated-image {
            width: 200px;
            height: auto;
            margin-bottom: 2rem;
            animation: pulse 2s infinite alternate;
        }

        @keyframes pulse {
            0% {
                transform: scale(1);
            }

            100% {
                transform: scale(1.1);
            }
        }

        .links {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
        }

        .links a {
            color: #007bff;
            text-decoration: none;
            margin: 0 1rem;
            font-size: 1.2rem;
        }

        .links a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="container">
        <img src="{{ asset('images/audiobook_icon.png') }}" alt="Audiobook Icon" class="animated-image">
        <h1>Audiobook Librarian</h1>
        <p>Your personalized archive for all your audiobooks and ebooks!</p>

        @if (Route::has('login'))
            <div class="links">
                @auth
                    <a href="{{ url('/home') }}">Home</a>
                @else
                    <a href="{{ route('login') }}">Log in</a>

                    @if (Route::has('register'))
                        <a href="{{ route('register') }}">Register</a>
                    @endif
                @endauth
            </div>
        @endif
    </div>
</body>

</html>
