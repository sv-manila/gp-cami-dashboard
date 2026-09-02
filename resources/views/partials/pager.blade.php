{{--
    Shared pager for every server-paged list in the dashboard.

    Works with all three of Laravel's paginators — length-aware (accounts,
    quality flags), simple (review queue, account members) and cursor (search
    results) — because the four navigation methods used here are common to all
    of them. Only the length-aware one knows how many pages exist; the other two
    deliberately never count, because counting is the expensive half of paging a
    13M-row hub table. The middle slot says what each kind can actually support
    rather than inventing a total.

    @param  \Illuminate\Contracts\Pagination\Paginator  $paginator  already ->appends()ed by the caller
    @param  string  $label     plural noun for the total, length-aware lists only
    @param  bool    $standalone  true when the pager is not tucked inside a bordered list
--}}
@php
    $label = $label ?? 'rows';
    $standalone = $standalone ?? true;
    $aware = $paginator instanceof \Illuminate\Pagination\LengthAwarePaginator;
    $numbered = ! ($paginator instanceof \Illuminate\Pagination\CursorPaginator);
@endphp

@if ($paginator->hasPages())
    <div class="pager{{ $standalone ? ' standalone' : '' }}">
        @if ($paginator->onFirstPage())
            <span class="btn-mini disabled">← Previous</span>
        @else
            <a class="btn-mini" href="{{ $paginator->previousPageUrl() }}">← Previous</a>
        @endif

        <span class="pager-info muted">
            @if ($aware)
                Page {{ number_format($paginator->currentPage()) }} of {{ number_format($paginator->lastPage()) }}
                · {{ number_format($paginator->total()) }} {{ $label }}
            @elseif ($numbered)
                Page {{ number_format($paginator->currentPage()) }}
            @endif
        </span>

        @if ($paginator->hasMorePages())
            <a class="btn-mini" href="{{ $paginator->nextPageUrl() }}">Next →</a>
        @else
            <span class="btn-mini disabled">Next →</span>
        @endif
    </div>
@endif
