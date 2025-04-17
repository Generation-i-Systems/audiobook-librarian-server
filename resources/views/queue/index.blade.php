@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>My Book Queue</h1>

        <ul id="book-queue">
            @foreach ($queue as $item)
                <li class="queue-item" data-id="{{ $item->id }}">
                    {{ $item->order }}. {{ $item->book->title }} by {{ $item->book->author }}
                    <form action="{{ route('queue.remove', $item->book) }}" method="POST" style="display: inline;">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                    </form>
                </li>
            @endforeach
        </ul>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.css" />

    <script>
        $(function() {
            $("#book-queue").sortable({
                update: function(event, ui) {
                    var queueData = [];
                    $('#book-queue li').each(function(index) {
                        queueData.push({
                            id: $(this).data('id'),
                            order: index + 1
                        });
                    });

                    $.ajax({
                        url: "{{ route('queue.updateOrder') }}",
                        type: "POST",
                        data: {
                            queue: queueData,
                            _token: '{{ csrf_token() }}'
                        },
                        success: function(response) {
                            console.log(response);
                            // Optionally, display a success message.
                        },
                        error: function(xhr, status, error) {
                            console.error(xhr.responseText);
                            // Handle errors appropriately
                        }
                    });
                }
            });
        });
    </script>
@endsection
