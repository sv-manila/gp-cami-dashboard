{{--
    Inline SVG sparkline, no chart library.

    The series is short (30 daily points at most) and the shape is the whole
    message — "flat" or "climbing" reads at a glance, exact values live on the
    card above it. Drawn with a flat baseline so a series that never moves is
    visibly a straight line rather than noise scaled to fill the box.

    @param array<int,int> $series  oldest value first
--}}
@php
    $w = 84; $h = 22; $pad = 2;
    $min = min($series); $max = max($series);
    $span = max($max - $min, 1);
    $step = count($series) > 1 ? ($w - 2 * $pad) / (count($series) - 1) : 0;

    $points = [];
    foreach ($series as $i => $v) {
        $x = $pad + $i * $step;
        // A dead-flat series has no meaningful range; centre it instead of
        // pinning it to the top of the box.
        $y = $max === $min ? $h / 2 : $h - $pad - (($v - $min) / $span) * ($h - 2 * $pad);
        $points[] = round($x, 1) . ',' . round($y, 1);
    }

    $rising = end($series) > reset($series);
    $flat = $max === $min;
@endphp
<svg class="spark {{ $flat ? 'flat' : ($rising ? 'up' : 'down') }}" width="{{ $w }}" height="{{ $h }}"
     viewBox="0 0 {{ $w }} {{ $h }}" role="img"
     aria-label="{{ count($series) }} daily snapshots, {{ number_format(reset($series)) }} to {{ number_format(end($series)) }}">
    <polyline points="{{ implode(' ', $points) }}" fill="none" stroke="currentColor" stroke-width="1.5"
              stroke-linejoin="round" stroke-linecap="round"/>
    @php [$lx, $ly] = explode(',', end($points)); @endphp
    <circle cx="{{ $lx }}" cy="{{ $ly }}" r="1.8" fill="currentColor"/>
</svg>
