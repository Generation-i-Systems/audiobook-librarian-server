@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Create New Badge</h1>

    <form action="{{ route('badges.store') }}" method="POST" enctype="multipart/form-data">
        @include('badges._form')
    </form>
</div>
@endsection
