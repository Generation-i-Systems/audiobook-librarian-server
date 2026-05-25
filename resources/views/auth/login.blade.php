<?php
?>
@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">{{ __('Login') }}</div>

                    <div class="card-body">
                        <form method="POST" action="{{ route('login') }}">
                            @csrf

                            <div class="row mb-3">
                                <label for="login"
                                    class="col-md-4 col-form-label text-md-end">{{ __('Email or Username') }}</label>

                                <div class="col-md-6">
                                    <input id="login" type="text" class="form-control @error('login') is-invalid @enderror"
                                        name="login" value="{{ old('login') }}" required autocomplete="username" autofocus>

                                    @error('login')
                                        <span class="invalid-feedback" role="alert">
                                            <strong>{{ $message }}</strong>
                                        </span>
                                    @enderror
                                </div>
                            </div>

                            <div class="row mb-3">
                                <label for="password"
                                    class="col-md-4 col-form-label text-md-end">{{ __('Password') }}</label>

                                <div class="col-md-6">
                                    <input id="password" type="password"
                                        class="form-control @error('password') is-invalid @enderror" name="password"
                                        required autocomplete="current-password">

                                    @error('password')
                                        <span class="invalid-feedback" role="alert">
                                            <strong>{{ $message }}</strong>
                                        </span>
                                    @enderror
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6 offset-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>

                                        <label class="form-check-label" for="remember">
                                            {{ __('Remember Me') }}
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="row mb-0">
                                <div class="col-md-8 offset-md-4">
                                    <button type="submit" class="btn btn-primary">
                                        {{ __('Login') }}
                                    </button>

                                    @if (Route::has('password.request'))
                                        <a class="btn btn-link" href="{{ route('password.request') }}">
                                            {{ __('Forgot Your Password?') }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <a href="{{ route('login.google') }}" class="btn btn-outline-primary"
                        style="background: #fff; color: #444; border-color: #ddd;">
                        <img src="https://developers.google.com/identity/images/g-logo.png" alt="Google Logo"
                            style="width:20px; margin-right:8px; vertical-align:middle;">
                        Sign in with Google
                    </a>
                </div>

                @if ($otpEnabled)
                    <div class="mt-4 mb-3">
                        <div class="text-center mb-3" style="color: #888;">
                            <span style="background: #fff; padding: 0 12px; font-size: 13px;">or sign in with a code</span>
                        </div>
                        <div class="card">
                            <div class="card-body">
                                <div class="row mb-3">
                                    <label for="otp-email"
                                        class="col-md-4 col-form-label text-md-end">{{ __('Email') }}</label>
                                    <div class="col-md-6">
                                        <input id="otp-email" type="email"
                                            class="form-control" placeholder="your@email.com" required>
                                    </div>
                                </div>
                                <div class="row mb-0">
                                    <div class="col-md-8 offset-md-4">
                                        <button type="button" id="send-otp-btn" class="btn btn-outline-primary">
                                            Send sign-in code
                                        </button>
                                        <span id="otp-status" class="ms-2" style="display:none;"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    @push('scripts')
                        <script>
                            (function ($) {
                                "use strict";
                                $(document).ready(function () {
                                    var $btn = $('#send-otp-btn');
                                    var $email = $('#otp-email');
                                    var $status = $('#otp-status');

                                    $btn.on('click', function () {
                                        var email = $email.val().trim();
                                        if (!email) {
                                            $status.text('Please enter your email.').removeClass('text-success').addClass('text-danger').show();
                                            return;
                                        }

                                        $btn.prop('disabled', true).text('Sending...');
                                        $status.hide();

                                        $.ajax({
                                            url: '{{ route('auth.otp.request') }}',
                                            method: 'POST',
                                            data: {
                                                email: email,
                                                _token: '{{ csrf_token() }}'
                                            },
                                            success: function () {
                                                $status.text('Check your email for the sign-in code.').removeClass('text-danger').addClass('text-success').show();
                                            },
                                            error: function (xhr) {
                                                var msg = 'Failed to send. Please try again.';
                                                if (xhr.responseJSON && xhr.responseJSON.message) {
                                                    msg = xhr.responseJSON.message;
                                                }
                                                $status.text(msg).removeClass('text-success').addClass('text-danger').show();
                                            },
                                            complete: function () {
                                                $btn.prop('disabled', false).text('Send sign-in code');
                                            }
                                        });
                                    });
                                });
                            })(window.jQuery);
                        </script>
                    @endpush
                @endif
            </div>
        </div>
    </div>
@endsection
