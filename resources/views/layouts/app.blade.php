<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Audiobook Librarian') }}</title>

    <!-- Fonts -->
    <link rel="dns-prefetch" href="//fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=Nunito" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
        integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}" />

    <!-- Scripts -->
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
</head>

<body>
    <div id="app">
        <nav class="navbar navbar-expand-md navbar-dark bg-primary shadow-sm">
            <div class="container">
                <a class="navbar-brand" href="{{ url('/') }}">
                    <img src="{{ asset('images/ablibrarian_logo_horizontal_white.svg') }}" alt="Audiobook Librarian"
                        height="30">
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent"
                    aria-expanded="false" aria-label="{{ __('Toggle navigation') }}">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <!-- Left Side Of Navbar -->
                    <ul class="navbar-nav me-auto">
                        @auth
                            @if(request()->is('admin/*'))
                                <!-- Admin Links (Show only in admin section) -->
                                <li class="nav-item">
                                    <a class="nav-link" style="color:white"
                                        href="{{ route('admin.genres.index') }}">{{ __('Genres') }}</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" style="color:white"
                                        href="{{ route('admin.authors.index') }}">{{ __('Authors') }}</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" style="color:white"
                                        href="{{ route('admin.books.index') }}">{{ __('Books') }}</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" style="color:white"
                                        href="{{ route('admin.messages.index') }}">{{ __('Messages') }}</a>
                                </li>

                            @else
                                <!-- Public Links (Show on public pages) -->
                                <li class="nav-item">
                                    <a class="nav-link" style="color:white"
                                        href="{{ route('books.index') }}">{{ __('Books') }}</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" style="color:white"
                                        href="{{ route('queue.index') }}">{{ __('Book Queue') }}</a>
                                </li>
                            @endif
                        @endauth
                    </ul>

                    <!-- Right Side Of Navbar -->
                    <ul class="navbar-nav ms-auto">
                        <!-- Authentication Links -->
                        @guest
                            @if (Route::has('login'))
                                <li class="nav-item">
                                    <a class="nav-link" style="color:white" href="{{ route('login') }}">{{ __('Login') }}</a>
                                </li>
                            @endif

                            @if (Route::has('register'))
                                <li class="nav-item">
                                    <a class="nav-link" style="color:white"
                                        href="{{ route('register') }}">{{ __('Register') }}</a>
                                </li>
                            @endif
                        @else
                            <li class="nav-item dropdown">
                                <a id="navbarDropdown" class="nav-link dropdown-toggle" href="#" role="button"
                                    data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false" v-pre
                                    style="color:white">
                                    {{ Auth::user()->name }}
                                </a>

                                <div class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                                    <a class="dropdown-item" href="{{ route('profile.index') }}">
                                        {{ __('Profile') }}
                                    </a>

                                    @if (Auth::user()->role === 'admin')
                                        @if(request()->is('admin/*'))
                                            <a class="dropdown-item" href="{{ route('books.index') }}"
                                                onclick="event.preventDefault(); document.getElementById('user-mode-form').submit();">Switch
                                                to User Mode</a>

                                        @else
                                            <a class="dropdown-item" href="{{ route('admin.index') }}"
                                                onclick="event.preventDefault(); document.getElementById('admin-mode-form').submit();">Switch
                                                to Admin Mode</a>

                                        @endif
                                    @endif

                                    <a class="dropdown-item" href="{{ route('logout') }}" onclick="event.preventDefault();
                                                             document.getElementById('logout-form').submit();">
                                        {{ __('Logout') }}
                                    </a>

                                    <form id="logout-form" action="{{ route('logout') }}" method="POST" class="d-none">
                                        @csrf
                                    </form>
                                </div>
                            </li>
                        @endguest
                    </ul>
                </div>
            </div>
        </nav>

        <main class="py-4">
            @yield('content')
        </main>
    </div>
    @yield('scripts')
</body>

</html>
