@if ($paginator->hasPages())
    <nav>
        <ul class="pagination">
            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true" aria-label="First">
                    <span class="page-link" aria-hidden="true">&laquo; First</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->url(1) }}" rel="first" aria-label="First">&laquo; First</a>
                </li>
            @endif

            @if ($paginator->onFirstPage())
                <li class="page-item disabled" aria-disabled="true" aria-label="Previous">
                    <span class="page-link" aria-hidden="true">&lsaquo; Previous</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Previous">&lsaquo;
                        Previous</a>
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
                    <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Next">Next &rsaquo;</a>
                </li>
            @else
                <li class="page-item disabled" aria-disabled="true" aria-label="Next">
                    <span class="page-link" aria-hidden="true">Next &rsaquo;</span>
                </li>
            @endif

            @if ($paginator->currentPage() == $paginator->lastPage())
                <li class="page-item disabled" aria-disabled="true" aria-label="Last">
                    <span class="page-link" aria-hidden="true">Last &raquo;</span>
                </li>
            @else
                <li class="page-item">
                    <a class="page-link" href="{{ $paginator->url($paginator->lastPage()) }}" rel="last" aria-label="Last">Last
                        &raquo;</a>
                </li>
            @endif
        </ul>
    </nav>
@endif
