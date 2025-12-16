@if ($paginator->hasPages())
    @php
        $from = $paginator->firstItem() ?? 0;
        $to = $paginator->lastItem() ?? 0;
        $total = $paginator->total();
    @endphp

    <div class="d-flex justify-content-center align-items-center mb-2 px-2">
        <div class="text-muted small text-center">
            Showing {{ $from }} to {{ $to }} of {{ $total }} results
        </div>
    </div>

    <nav aria-label="Pagination Navigation" class="px-2 overflow-auto">
        <ul class="pagination justify-content-center mb-0 flex-nowrap">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true" aria-label="First">
                    <span class="page-link text-nowrap" aria-hidden="true">
                        &laquo;
                        <span class="d-none d-sm-inline">&nbsp;First</span>
                    </span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link text-nowrap" href="{{ $paginator->url(1) }}" rel="first" aria-label="First">
                        &laquo;
                        <span class="d-none d-sm-inline">&nbsp;First</span>
                    </a>
                </li>
            @endif

            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true" aria-label="Previous">
                    <span class="page-link text-nowrap" aria-hidden="true">
                        &lsaquo;
                        <span class="d-none d-sm-inline">&nbsp;Previous</span>
                    </span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link text-nowrap" href="{{ $paginator->previousPageUrl() }}" rel="prev"
                        aria-label="Previous">
                        &lsaquo;
                        <span class="d-none d-sm-inline">&nbsp;Previous</span>
                    </a>
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
                    <a class="page-link text-nowrap" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next">
                        <span class="d-none d-sm-inline">Next&nbsp;</span>
                        &rsaquo;
                    </a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true" aria-label="Next">
                    <span class="page-link text-nowrap" aria-hidden="true">
                        <span class="d-none d-sm-inline">Next&nbsp;</span>
                        &rsaquo;
                    </span>
                </li>
            @endif

            @if ($paginator->currentPage() == $paginator->lastPage())
                <li class="page-item disabled" aria-disabled="true" aria-label="Last">
                    <span class="page-link text-nowrap" aria-hidden="true">
                        <span class="d-none d-sm-inline">Last&nbsp;</span>
                        &raquo;
                    </span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link text-nowrap" href="{{ $paginator->url($paginator->lastPage()) }}" rel="last"
                        aria-label="Last">
                        <span class="d-none d-sm-inline">Last&nbsp;</span>
                        &raquo;
                    </a>
                </li>
            @endif
        </ul>
    </nav>
@endif
