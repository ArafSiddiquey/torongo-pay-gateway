@extends('layouts.admin')
@section('content')
<div class="page-head">
    <div>
        <h1>Language/Text</h1>
        <p class="hint">Editable customer-facing button, instruction, success and error text.</p>
    </div>
</div>

<form class="form" method="post" action="{{ route('admin.texts.save') }}">
    @csrf
    <div class="form-grid">
        @foreach($texts as $key => $rows)
            <div class="field">
                <label>{{ $key }} (English)</label>
                <input name="texts[{{ $key }}][en]" value="{{ $rows->firstWhere('lang','en')?->value }}">
            </div>
            <div class="field">
                <label>{{ $key }} (Bangla)</label>
                <input name="texts[{{ $key }}][bn]" value="{{ $rows->firstWhere('lang','bn')?->value }}">
            </div>
        @endforeach
    </div>
    <button class="btn">Save texts</button>
</form>
@endsection
