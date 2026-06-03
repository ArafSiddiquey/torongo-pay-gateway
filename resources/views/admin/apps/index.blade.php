@extends('layouts.admin')
@section('content')
<div class="page-head">
    <div>
        <h1>Apps & Plugins</h1>
        <p class="hint">Download the latest Torongo Pay tools for Android and WordPress.</p>
    </div>
</div>

<div class="apps-panel">
    <div>
        <h2>Tools & Downloads</h2>
        <p class="hint">Install these files only on your own trusted devices and websites.</p>
    </div>

    <div class="apps-grid">
        @foreach($artifacts as $artifact)
            <div class="download-card">
                <div class="download-icon {{ $artifact['icon'] }}">{{ $artifact['icon'] === 'android' ? 'A' : 'W' }}</div>
                <div>
                    <h3>{{ $artifact['title'] }}</h3>
                    <p>{{ $artifact['description'] }}</p>
                </div>
                <div class="download-meta">
                    <span>{{ $artifact['version'] }}</span>
                    @if($artifact['exists'])
                        <span>{{ $artifact['size'] }}</span>
                        <span>Updated {{ $artifact['updated_at'] }}</span>
                    @else
                        <span>File missing</span>
                    @endif
                </div>
                @if($artifact['exists'])
                    <a class="btn" href="{{ route('admin.apps.download', $artifact['key']) }}">Download</a>
                @else
                    <button class="btn secondary" type="button" disabled>Unavailable</button>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endsection
