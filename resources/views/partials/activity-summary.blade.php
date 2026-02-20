<div class="row">
    <!-- Badge Tips Card -->
    <div class="col-12 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <h5 class="mb-0">Next Achievements</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    @forelse(array_slice($activityData['tips'] ?? [], 0, 3) as $tip)
                        <div class="col-md-4 mb-3">
                            <div class="d-flex align-items-center p-3 border rounded bg-light h-100">
                                <div class="me-3 text-muted">
                                    @if($tip['icon'])
                                        <img src="{{ $tip['icon'] }}" alt="Badge" style="width: 40px; height: 40px; opacity: 0.6;">
                                    @elseif(isset($tip['emoji']) && $tip['emoji'])
                                        <span style="font-size: 2.5rem; opacity: 0.6;">{{ $tip['emoji'] }}</span>
                                    @else
                                        <i class="fas fa-medal fa-2x"></i>
                                    @endif
                                </div>
                                <div>
                                    <h6 class="mb-1">{{ $tip['badge_name'] }}</h6>
                                    <small class="text-muted">{{ $tip['tip'] }}</small>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-12 text-center text-muted">
                            <p class="mb-0">You are doing great! Keep listening to discover new achievements.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Activity Tabs -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">User Activity</h5>
    </div>
    <div class="card-body">
        <ul class="nav nav-tabs mb-3" id="activityTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="badges-tab" data-bs-toggle="tab" data-bs-target="#badges" type="button" role="tab">Badges</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="progress-tab" data-bs-toggle="tab" data-bs-target="#progress" type="button" role="tab">Reading Progress</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="statuses-tab" data-bs-toggle="tab" data-bs-target="#statuses" type="button" role="tab">Books & Downloads</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="reviews-tab" data-bs-toggle="tab" data-bs-target="#reviews" type="button" role="tab">Reviews</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="recommendations-tab" data-bs-toggle="tab" data-bs-target="#recommendations" type="button" role="tab">Recommendations</button>
            </li>
            @if(isset($userId))
                <li class="nav-item ms-auto" role="presentation">
                    <a class="nav-link" href="{{ route('admin.events.timeline', $userId) }}">Event Timeline</a>
                </li>
                <li class="nav-item" role="presentation">
                    <a class="nav-link" href="{{ route('admin.books.positions', $userId) }}">Books in Progress</a>
                </li>
            @endif
        </ul>
        <div class="tab-content" id="activityTabsContent">

            <!-- Badges -->
            <div class="tab-pane fade show active" id="badges" role="tabpanel">
                @php
                    // Flatten the badges from categories into a single collection/array
                    $allBadges = collect($activityData['badges_by_category'] ?? [])->flatten(1);
                @endphp

                <div class="row row-cols-3 row-cols-sm-4 row-cols-md-6 row-cols-lg-8 g-2 mt-3">
                    @forelse($allBadges as $item)
                        <div class="col text-center" data-bs-toggle="tooltip" data-bs-placement="top" title="{{ $item['description'] }}">
                            <div class="card h-100 {{ $item['is_earned'] ? 'border-success' : 'border-light bg-light' }}">
                                <div class="card-body p-1 d-flex flex-column align-items-center justify-content-center">
                                    <div class="position-relative mb-1">
                                        @if($item['icon'])
                                            <img src="{{ $item['icon'] }}"
                                                 alt="{{ $item['name'] }}"
                                                 style="width: 32px; height: 32px; {{ $item['is_earned'] ? '' : 'filter: grayscale(100%) opacity(0.4);' }}">
                                        @elseif(isset($item['emoji']) && $item['emoji'])
                                            <span style="font-size: 2rem; {{ $item['is_earned'] ? '' : 'opacity: 0.4; filter: grayscale(100%);' }}">{{ $item['emoji'] }}</span>
                                        @else
                                            <i class="fas fa-medal fa-2x {{ $item['is_earned'] ? 'text-warning' : 'text-secondary' }}" style="{{ $item['is_earned'] ? '' : 'opacity: 0.3;' }}"></i>
                                        @endif

                                        @if($item['is_earned'])
                                            <span class="position-absolute top-0 start-100 translate-middle p-1 bg-success border border-light rounded-circle" style="width: 8px; height: 8px;">
                                                <span class="visually-hidden">Earned</span>
                                            </span>
                                        @endif
                                    </div>
                                    <small class="fw-bold text-truncate w-100" style="font-size: 0.65rem;" title="{{ $item['name'] }}">{{ $item['name'] }}</small>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="col-12 text-center text-muted p-4">No badges found in the system.</div>
                    @endforelse
                </div>
            </div>

            <!-- Reading Progress -->
            <div class="tab-pane fade" id="progress" role="tabpanel">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Book</th>
                                <th>Progress</th>
                                <th>Last Listened</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($activityData['progress'] ?? [] as $item)
                                <tr>
                                    <td>{{ $item['book_title'] }}</td>
                                    <td>
                                        <div class="progress" style="height: 10px;">
                                            <div class="progress-bar" role="progressbar" style="width: {{ $item['percentage'] }}%"></div>
                                        </div>
                                        {{ number_format($item['percentage'], 1) }}%
                                    </td>
                                    <td>{{ $item['last_listened_at'] ? \Carbon\Carbon::parse($item['last_listened_at'])->diffForHumans() : 'Never' }}</td>
                                    <td>
                                        @if($item['completed'])
                                            <span class="badge bg-success">Completed</span>
                                        @else
                                            <span class="badge bg-primary">In Progress</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted">No reading progress found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Books & Downloads -->
            <div class="tab-pane fade" id="statuses" role="tabpanel">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Book</th>
                                <th>Status</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($activityData['statuses'] ?? [] as $item)
                                <tr>
                                    <td>{{ $item['book_title'] }}</td>
                                    <td><span class="badge bg-secondary">{{ $item['status'] }}</span></td>
                                    <td>{{ \Carbon\Carbon::parse($item['updated_at'])->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted">No book status data found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Reviews -->
            <div class="tab-pane fade" id="reviews" role="tabpanel">
                <div class="list-group">
                    @forelse($activityData['reviews'] ?? [] as $item)
                        <div class="list-group-item">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">{{ $item['book_title'] }}</h6>
                                <small>{{ \Carbon\Carbon::parse($item['created_at'])->diffForHumans() }}</small>
                            </div>
                            <div class="mb-1">
                                @for($i = 1; $i <= 5; $i++)
                                    <i class="fas fa-star {{ $i <= $item['age_rating'] ? 'text-warning' : 'text-muted' }}"></i>
                                @endfor
                                <span class="badge bg-info ms-2">{{ $item['content_rating'] }}</span>
                            </div>
                            <p class="mb-1">{{ $item['comment'] }}</p>
                        </div>
                    @empty
                        <div class="text-center text-muted">No reviews submitted.</div>
                    @endforelse
                </div>
            </div>

            <!-- Recommendations -->
            <div class="tab-pane fade" id="recommendations" role="tabpanel">
                <div class="list-group">
                    @forelse($activityData['recommendations'] ?? [] as $item)
                        <div class="list-group-item">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1">{{ $item['book_title'] }}</h6>
                                <small>{{ \Carbon\Carbon::parse($item['created_at'])->diffForHumans() }}</small>
                            </div>
                            <p class="mb-1">Recommended by <strong>{{ $item['sender_name'] }}</strong></p>
                            @if($item['message'])
                                <p class="mb-1 text-muted">"{{ $item['message'] }}"</p>
                            @endif
                            <small>
                                @if($item['acknowledged_at'])
                                    <span class="text-success"><i class="fas fa-check-double"></i> Read {{ \Carbon\Carbon::parse($item['acknowledged_at'])->diffForHumans() }}</span>
                                @else
                                    <span class="text-muted"><i class="fas fa-clock"></i> Not yet read</span>
                                @endif
                            </small>
                        </div>
                    @empty
                        <div class="text-center text-muted">No recommendations received.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
