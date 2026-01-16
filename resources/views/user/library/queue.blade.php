@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold">Reading Queue</h1>
        <a href="{{ route('my-library.goals') }}" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
            View Goals
        </a>
    </div>

    @if ($queuedBooks->isEmpty())
        <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4" role="alert">
            <p>Your reading queue is empty. Add some books to your queue!</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach ($queuedBooks as $status)
                <div class="bg-white shadow-lg rounded-lg overflow-hidden flex flex-col">
                    <div class="p-4 flex-grow">
                        <div class="flex items-center mb-4">
                            <div class="flex-shrink-0 w-16 h-20">
                                @if($status->book->cover_image)
                                    <img class="w-full h-full rounded-md object-cover" src="{{ route('cover.proxy', ['path' => $status->book->cover_image]) }}" alt="{{ $status->book->title }}">
                                @else
                                    <div class="w-full h-full bg-gray-200 rounded-md flex items-center justify-center text-gray-400">
                                        <i class="fas fa-book fa-2x"></i>
                                    </div>
                                @endif
                            </div>
                            <div class="ml-4">
                                <h2 class="text-xl font-semibold leading-tight">{{ $status->book->title }}</h2>
                                <p class="text-gray-600 text-sm mt-1">Order: {{ $status->order }}</p>
                            </div>
                        </div>
                        
                        <div class="mt-4 space-y-2">
                            <div class="flex items-center text-sm text-gray-600">
                                <i class="fas fa-calendar-plus w-5"></i>
                                <span class="ml-2">Added: {{ $status->created_at->diffForHumans() }}</span>
                            </div>
                            
                            <div class="flex items-center text-sm {{ $status->target_date ? 'text-blue-600 font-semibold' : 'text-gray-400 italic' }}">
                                <i class="fas fa-bullseye w-5"></i>
                                <span class="ml-2">
                                    {{ $status->target_date ? 'Target: ' . $status->target_date->format('M d, Y') : 'No target date set' }}
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gray-50 p-4 border-t flex justify-between">
                        <button 
                            data-book-id="{{ $status->book->id }}" 
                            data-target-date="{{ $status->target_date?->format('Y-m-d') }}"
                            onclick="openTargetModal(this)"
                            class="text-blue-600 hover:text-blue-800 text-sm font-bold">
                            Set Target
                        </button>
                        <form action="{{ route('books.show', $status->book->id) }}" method="GET">
                            <button type="submit" class="text-gray-600 hover:text-gray-800 text-sm font-bold">
                                Details
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

<!-- Target Date Modal -->
<div id="target-date-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white p-8 rounded-lg shadow-xl w-96">
        <h2 class="text-xl font-bold mb-4">Set Target Date</h2>
        <form id="target-date-form">
            <input type="hidden" id="modal-book-id">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="target_date">
                    Completion Goal
                </label>
                <input type="date" id="modal-target-date" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
            </div>
            <div class="flex justify-end space-x-4">
                <button type="button" onclick="closeTargetModal()" class="text-gray-500 hover:text-gray-700 font-bold py-2 px-4">
                    Cancel
                </button>
                <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                    Save
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openTargetModal(btn) {
        document.getElementById('modal-book-id').value = btn.getAttribute('data-book-id');
        document.getElementById('modal-target-date').value = btn.getAttribute('data-target-date');
        document.getElementById('target-date-modal').classList.remove('hidden');
        document.getElementById('target-date-modal').classList.add('flex');
    }

    function closeTargetModal() {
        document.getElementById('target-date-modal').classList.add('hidden');
        document.getElementById('target-date-modal').classList.remove('flex');
    }

    document.getElementById('target-date-form').onsubmit = function(e) {
        e.preventDefault();
        const bookId = document.getElementById('modal-book-id').value;
        const targetDate = document.getElementById('modal-target-date').value;

        fetch(`/api/v1/status/${bookId}/set`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                status: 'queue',
                target_date: targetDate
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.status) {
                window.location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred.');
        });
    };
</script>
@endsection
