@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>{{ __('Account Requests') }}</h1>

        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>
        @endif

        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('Name') }}</th>
                    <th>{{ __('Email') }}</th>
                    <th>{{ __('Status') }}</th>
                    <th>{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($accountRequests as $request)
                    <tr>
                        <td>{{ $request['name'] }}</td>
                        <td>{{ $request['email'] }}</td>
                        <td>{{ $request['status'] }}</td>
                        <td>
                            <form action="{{ route('admin.account_requests.update', ['account_request' => $request['id']]) }}"
                                method="POST" style="display:inline;">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-success btn-sm">{{ __('Approve') }}</button>
                            </form>
                            <form action="{{ route('admin.account_requests.destroy', ['account_request' => $request['id']]) }}"
                                method="POST" style="display:inline;"
                                onsubmit="return confirm({{ \Illuminate\Support\Js::from(__('Are you sure you want to reject the request from ":name"?', ['name' => $request['name']])) }})">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-danger btn-sm">{{ __('Reject') }}</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
