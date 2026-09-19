<p>A book metadata edit is awaiting approval.</p>

<p>
    <strong>Book:</strong> {{ $contribution->book->title }}<br>
    <strong>Submitted by:</strong> {{ $contribution->submitter->name }} ({{ $contribution->submitter->email }})
</p>

<ul>
    @foreach ($contribution->changes as $field => $change)
        <li>
            <strong>{{ $field }}:</strong>
            {{ $change['original'] ?? '(empty)' }} &rarr; {{ $change['new'] ?? '(empty)' }}
        </li>
    @endforeach
</ul>

<p>
    Review pending edits at <code>GET /api/v1/admin/contributions</code>, then approve with
    <code>POST /api/v1/admin/contributions/{{ $contribution->id }}/approve</code> or reject with
    <code>POST /api/v1/admin/contributions/{{ $contribution->id }}/reject</code>.
</p>
