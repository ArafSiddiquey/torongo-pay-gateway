<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Torongo Pay Admin</title>
    <link rel="stylesheet" href="{{ asset('assets/css/admin.css') }}?v={{ filemtime(public_path('assets/css/admin.css')) }}">
</head>
<body>
<div class="app">
    <aside class="side">
        <div class="brand">
            <img class="brand-mark" src="{{ asset('assets/img/torongo-pay-mark.svg') }}" alt="Torongo Pay">
            <div>
                <div class="logo">Torongo Pay</div>
                <div class="sublogo">Payment Gateway Admin</div>
            </div>
        </div>
        <nav class="nav">
            <div class="nav-group">Overview</div>
            <a class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}" href="{{ route('admin.dashboard') }}">Dashboard</a>
            <a class="{{ request()->routeIs('admin.transactions') && request('status') !== 'pending' ? 'active' : '' }}" href="{{ route('admin.transactions') }}">Transactions</a>

            <div class="nav-group">Payments</div>
            <a class="{{ request()->routeIs('admin.invoices') ? 'active' : '' }}" href="{{ route('admin.invoices') }}">Invoices</a>
            <a class="{{ request()->routeIs('admin.transactions') && request('status') === 'pending' ? 'active' : '' }}" href="{{ route('admin.transactions', ['status' => 'pending']) }}">Pending Requests</a>
            <a class="{{ request()->routeIs('admin.sms') ? 'active' : '' }}" href="{{ route('admin.sms') }}">SMS Data</a>

            <div class="nav-group">Gateway</div>
            <a class="{{ request()->routeIs('admin.methods.*') ? 'active' : '' }}" href="{{ route('admin.methods.index') }}">Payment Methods</a>
            <a class="{{ request()->routeIs('admin.devices.*') ? 'active' : '' }}" href="{{ route('admin.devices.index') }}">SMS Devices</a>

            <div class="nav-group">Configuration</div>
            <a class="{{ request()->routeIs('admin.settings') ? 'active' : '' }}" href="{{ route('admin.settings') }}">Gateway Setup</a>
        </nav>
        <form id="logoutForm" class="logout-wrap" method="post" action="{{ route('admin.logout') }}">@csrf<button class="logout">Logout</button></form>
    </aside>
    <main class="main">
        <header class="admin-top">
            <div>
                <span class="eyebrow"><img class="topbar-mark" src="{{ asset('assets/img/torongo-pay-mark.svg') }}" alt=""> Torongo Pay</span>
                <b>Payment Gateway Admin</b>
            </div>
        </header>
        <section class="content-wrap">
            @if(session('ok'))<div class="ok">{{ session('ok') }}</div>@endif
            @if($errors->any())<div class="err">{{ $errors->first() }}</div>@endif
            @yield('content')
        </section>
    </main>
</div>
<a class="help-float" href="{{ route('admin.devices.index') }}">?</a>
</body>
</html>
