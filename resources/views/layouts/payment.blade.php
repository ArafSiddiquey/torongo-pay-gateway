<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $settings?->get('gateway_name', 'Torongo Pay') ?? 'Torongo Pay' }}</title>
    <link rel="stylesheet" href="{{ asset('assets/css/gateway.css') }}">
</head>
<body>
<div id="copyToastStack" class="copy-toast-stack" aria-live="polite" aria-atomic="false"></div>
@yield('content')
</body>
</html>
