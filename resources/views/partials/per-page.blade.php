{{--
    Page-size switch for a server-paged list.

    Sizes come from the same whitelist the controllers validate against, so a
    link here can never ask for a page size the request would silently ignore.

    @param  int    $current  the page size in effect
    @param  array  $sizes    optional override of the offered sizes
--}}
@php
    $sizes = $sizes ?? \App\Services\QueryInput::PAGE_SIZES;
    // page and cursor are dropped: "page 4" and a keyset cursor both describe a
    // position cut at the old page size, and neither survives changing it.
    $base = collect(request()->query())->except(['page', 'cursor'])->all();
@endphp

<span class="per-page muted">
    Rows
    @foreach ($sizes as $size)
        <a href="{{ request()->url() }}?{{ http_build_query(array_merge($base, ['per_page' => $size])) }}"
           class="{{ (int) $current === (int) $size ? 'active' : '' }}">{{ $size }}</a>
    @endforeach
</span>
