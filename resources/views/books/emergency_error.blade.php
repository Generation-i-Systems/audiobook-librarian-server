@extends('layouts.app')

@section('content')
<div class="container">
    <div class="alert alert-danger">
        <h4>System Error</h4>
        <p>The book system is experiencing memory issues. Please contact the administrator.</p>
        <hr>
        <p class="mb-0"><strong>Error:</strong> {{ $error }}</p>
    </div>
    
    <div class="text-center">
        <a href="{{ route('home') }}" class="btn btn-primary">Return to Home</a>
    </div>
</div>
@endsection