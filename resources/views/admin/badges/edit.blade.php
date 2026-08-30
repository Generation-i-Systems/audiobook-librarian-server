@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Edit Badge: {{ $badge->name }}</h1>

    <form action="{{ route('admin.badges.update', $badge) }}" method="POST" enctype="multipart/form-data">
        @include('admin.badges._form')
    </form>
</div>
@endsection
