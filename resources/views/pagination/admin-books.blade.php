@if ($paginator->hasPages())
    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
        <div class="text-muted small">
            @php
                $from = $paginator->firstItem() ?? 0;
                $to = $paginator->lastItem() ?? 0;
                $total = $paginator->total();
            @endphp
            Showing {{ $from }} to {{ $to }} of {{ $total }} results
        </div>

        <nav aria-label="Pagination Navigation">
            <ul class="pagination mb-0">
                @if ($paginator->onFirstPage())
                    <li class="page-item disabled" aria-disabled="true" aria-label="First">
                        <span class="page-link text-nowrap" aria-hidden="true">&laquo;&nbsp;First</span>
                    </li>
                @else
                    <li class="page-item">
                        <a class="page-link text-nowrap" href="{{ $paginator->url(1) }}" rel="first"
                            aria-label="First">&laquo;&nbsp;First</a>
                    </li>
                @endif

                @if ($paginator->onFirstPage())
                    <li class="page-item disabled" aria-disabled="true" aria-label="Previous">
                        <span class="page-link text-nowrap" aria-hidden="true">&lsaquo;&nbsp;Previous</span>
                    </li>
                @else
                    <li class="page-item">
                        <a class="page-link text-nowrap" href="{{ $paginator->previousPageUrl() }}" rel="prev"
                            aria-label="Previous">&lsaquo;&nbsp;Previous</a>
                    </li>
                @endif

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <li class="page-item disabled" aria-disabled="true">
                            <span class="page-link">{{ $element }}</span>
                        </li>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <li class="page-item active" aria-current="page">
                                    <span class="page-link">{{ $page }}</span>
                                </li>
                            @else
                                <li class="page-item">
                                    <a class="page-link" href="{{ $url }}">{{ $page }}</a>
                                </li>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                @if ($paginator->hasMorePages())
                    <li class="page-item">
                        <a class="page-link text-nowrap" href="{{ $paginator->nextPageUrl() }}" rel="next"
                            aria-label="Next">Next&nbsp;&rsaquo;</a>
                    </li>
                @else
                    <li class="page-item disabled" aria-disabled="true" aria-label="Next">
                        <span class="page-link text-nowrap" aria-hidden="true">Next&nbsp;&rsaquo;</span>
                    </li>
                @endif

                @if ($paginator->currentPage() == $paginator->lastPage())
                    <li class="page-item disabled" aria-disabled="true" aria-label="Last">
                        <span class="page-link text-nowrap" aria-hidden="true">Last&nbsp;&raquo;</span>
                    </li>
                @else
                    <li class="page-item">
                        <a class="page-link text-nowrap" href="{{ $paginator->url($paginator->lastPage()) }}" rel="last"
                            aria-label="Last">Last&nbsp;&raquo;</a>
                    </li>
                @endif
            </ul>
        </nav>
    </div>
@endif
