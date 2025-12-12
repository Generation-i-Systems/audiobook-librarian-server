@component('mail::layout')
{{-- Header --}}
@slot('header')
@component('mail::header', ['url' => config('app.url')])
{{ config('app.name') }}
@endcomponent
@endslot

# New User Registration

A new user has registered via the **{{ $meta['source'] ?? 'unknown' }}** channel.

## User details

- **Name**: {{ $user['name'] ?? 'N/A' }}
- **Username**: {{ $user['username'] ?? 'N/A' }}
- **Email**: {{ $user['email'] ?? 'N/A' }}
- **Role**: {{ $user['role'] ?? 'unverified' }}
- **User ID**: {{ $user['id'] ?? 'N/A' }}

## Request context

- **IP address**: {{ $meta['ip'] ?? 'N/A' }}
- **User agent**: {{ $meta['userAgent'] ?? 'N/A' }}
- **Requested at**: {{ $meta['requestedAt'] ?? now() }}

## Review and approval

@if(!empty($editUserUrl))
    - **Review user**: <a href="{{ $editUserUrl }}">Open user in admin panel</a>
@endif
- **User list**: <a href="{{ $usersIndexUrl }}">View all users</a>

@if(!empty($verifyUserUrl))
    The user is currently marked as **unverified**. To approve them, open the user in the admin panel and use the **Verify**
    action, which posts to:

    `POST {{ $verifyUserUrl }}`
@endif

To deny the signup, you can delete the user from the admin panel.

{{-- Footer --}}
@slot('footer')
@component('mail::footer')
© {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
@endcomponent
@endslot
@endcomponent
