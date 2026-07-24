<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'gp-cami dashboard')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="icon" href="{{ asset('sv-logo.png') }}">
</head>
<body>
    <div id="header">
        <div class="bar">
            <img src="{{ asset('sv-logo.png') }}" alt="Streamline Verify">
            <span class="title">gp-cami dashboard <span class="muted" style="font-weight:400;font-size:12px;">· dev</span></span>
        </div>
    </div>

    <main>
        @yield('content')
    </main>

    <footer>
        gp-cami dashboard — read-only view of the <b>golden_profile</b> hub. Development use only.
    </footer>
</body>
</html>
