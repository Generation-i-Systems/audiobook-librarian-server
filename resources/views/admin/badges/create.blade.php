@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Create New Badge</h1>

    <form action="{{ route('admin.badges.store') }}" method="POST" enctype="multipart/form-data">
        @include('admin.badges._form')
    </form>
</div>
@endsection
