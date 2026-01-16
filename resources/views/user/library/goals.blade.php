@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-6">Reading Goals</h1>

    @if ($goals->isEmpty())
        <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4" role="alert">
            <p>You haven't set any reading goals yet. Go to your <a href="{{ route('my-library.queue') }}" class="font-bold underline">Queue</a> and set target dates!</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach ($goals as $status)
                <div class="bg-white shadow-lg rounded-lg overflow-hidden border-t-4 {{ $status->target_date->isPast() ? 'border-red-500' : 'border-blue-500' }}">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <h2 class="text-xl font-bold text-gray-800">{{ $status->book->title }}</h2>
                            <span class="px-2 py-1 text-xs font-semibold rounded uppercase {{ $status->status === 'in_progress' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800' }}">
                                {{ str_replace('_', ' ', $status->status) }}
                            </span>
                        </div>
                        
                        <div class="space-y-3">
                            <div class="flex items-center text-gray-600">
                                <i class="fas fa-calendar-alt w-5"></i>
                                <span class="ml-2 font-medium">Target: {{ $status->target_date->format('M d, Y') }}</span>
                            </div>
                            
                            @if($status->target_date->isPast())
                                <p class="text-red-500 text-sm font-semibold">
                                    <i class="fas fa-exclamation-circle"></i>
                                    Overdue by {{ $status->target_date->diffInDays(now()) }} days
                                </p>
                            @else
                                <p class="text-green-600 text-sm font-semibold">
                                    <i class="fas fa-hourglass-half"></i>
                                    {{ $status->target_date->diffInDays(now()) }} days remaining
                                </p>
                            @endif

                            @if($status->status === 'in_progress' && $status->started_at)
                                <div class="text-xs text-gray-500">
                                    Started: {{ $status->started_at->format('M d, Y') }}
                                </div>
                            @endif
                        </div>
                        
                        <div class="mt-6 flex justify-between items-center">
                            <a href="{{ route('books.show', $status->book->id) }}" class="text-sm font-bold text-blue-600 hover:text-blue-800">
                                View Book
                            </a>
                            <button 
                                data-book-id="{{ $status->book->id }}"
                                data-target-date="{{ $status->target_date->format('Y-m-d') }}"
                                onclick="openGoalModal(this)"
                                class="text-sm font-bold text-gray-500 hover:text-gray-700">
                                Edit Goal
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

<!-- Simple Goal Edit Modal (Placeholder for actual JS implementation) -->
<div id="goal-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow-xl w-96">
        <h2 class="text-xl font-bold mb-4">Edit Reading Goal</h2>
        <form id="goal-form">
            <input type="hidden" id="goal-book-id">
            <div class="mb-4">
                <label class="block text-gray-700 text-sm font-bold mb-2" for="target_date">
                    Target Completion Date
                </label>
                <input type="date" id="goal-target-date" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
            </div>
            <div class="flex justify-end space-x-4">
                <button type="button" onclick="closeGoalModal()" class="text-gray-500 hover:text-gray-700 font-bold py-2 px-4">
                    Cancel
                </button>
                <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                    Save Goal
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openGoalModal(btn) {
        document.getElementById('goal-book-id').value = btn.getAttribute('data-book-id');
        document.getElementById('goal-target-date').value = btn.getAttribute('data-target-date');
        document.getElementById('goal-modal').classList.remove('hidden');
        document.getElementById('goal-modal').classList.add('flex');
    }

    function closeGoalModal() {
        document.getElementById('goal-modal').classList.add('hidden');
        document.getElementById('goal-modal').classList.remove('flex');
    }

    document.getElementById('goal-form').onsubmit = function(e) {
        e.preventDefault();
        const bookId = document.getElementById('goal-book-id').value;
        const targetDate = document.getElementById('goal-target-date').value;
        const status = '{{ $goals->first()->status ?? "queue" }}'; // Keep current status

        fetch(`/api/v1/status/${bookId}/set`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                status: status,
                target_date: targetDate
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.status) {
                window.location.reload();
            } else {
                alert('Error updating goal: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while updating the goal.');
        });
    };
</script>
@endsection
