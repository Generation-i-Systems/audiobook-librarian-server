@extends(isset($layout) ? $layout : 'layouts.app')

@section('content')
@include('admin.books.partials.form_styles')
@include('admin.books.partials.form_content')
@include('admin.books.partials.form_status_modals')
@include('admin.books.partials.form_support_modals')
@endsection
