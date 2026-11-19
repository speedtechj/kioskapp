@extends('layouts.app')
@section('content')
<link rel="stylesheet" href="{{ asset('branding/studio.css') }}">
<div class="app-shell" x-data="{navOpen:false}">
<x-sidebar :business="$business" :kiosk="$kiosk" mode="branding"/>
<main class="main brand-studio">
<header class="brand-row"><div><a href="{{ route('workspace',['business'=>$business,'tab'=>'kiosks']) }}">← Kiosks &amp; QR</a><h1>App branding</h1></div>
<label>Kiosk<select id="branch-picker">@foreach($kiosks as $branch)<option value="{{ route('kiosk.brand',[$business,$branch]) }}" @selected($branch->id===$kiosk->id)>{{ $branch->name }}</option>@endforeach</select></label></header>
@if(session('brand_saved'))<p class="success-banner" role="status">Branding saved for {{ $kiosk->name }}.</p>@endif
@foreach($errors->all() as $error)<p class="error" role="alert">{{ $error }}</p>@endforeach
<nav class="brand-tabs" aria-label="Kiosk settings"><button type="button" data-tab="settings" aria-pressed="true">App branding</button><button type="button" data-tab="cards" aria-pressed="false">QR card preview</button></nav>
<section id="settings" class="brand-grid">
<form class="card brand-box" method="post" enctype="multipart/form-data" action="{{ route('kiosk.brand.save',[$business,$kiosk]) }}">
@csrf
@method('PUT')
<label>Home Screen label<input id="brand-label" name="app_label" maxlength="40" value="{{ old('app_label',$kiosk->app_label) }}" placeholder="{{ $kiosk->name }}"></label>
<label>App icon &amp; favicon</label><div class="brand-row"><img data-icon class="large-icon" src="{{ $kiosk->appIcon(192) }}" alt="Current app icon"><label class="upload-box">Upload image<input type="file" id="brand-file" name="icon" accept="image/png"><small>Square PNG · 512 × 512 recommended · up to 3 MB</small></label></div>
<label>Browser tab preview</label><div class="tab-preview"><img data-icon src="{{ $kiosk->appIcon(32) }}" alt=""><span data-label>{{ $kiosk->appLabel() }}</span></div>
<button class="btn primary wide">Save changes</button></form>
<div class="card brand-box"><h2>Home Screen preview</h2><div class="phones">@foreach(['iPhone','Android'] as $device)<div><strong>{{ $device }}</strong><div class="phone-preview"><div class="phone-speaker"></div><img data-icon class="{{ $device==='Android'?'round':'' }}" src="{{ $kiosk->appIcon(192) }}" alt=""><span data-label>{{ $kiosk->appLabel() }}</span></div></div>@endforeach</div></div>
</section>
<section id="cards" class="card brand-box" hidden><div class="brand-row"><label>Ordering destination<select id="destination"><option data-url="{{ \App\Http\Controllers\SlugLinksController::scanUrl('takeout', [$business,$kiosk]) }}" data-qr="{{ route('takeout.qr',[$business,$kiosk],false) }}">Takeout</option>@foreach($tables as $table)<option data-url="{{ \App\Http\Controllers\SlugLinksController::scanUrl('menu', [$business,$kiosk,$table]) }}" data-qr="{{ route('qr',[$business,$kiosk,$table],false) }}">{{ $table->name }}</option>@endforeach</select></label>
<div><button type="button" class="btn" id="print-card" disabled>Print</button> <button type="button" class="btn primary" id="save-card" disabled>Download PDF</button></div></div>
<div class="scan-destination"><strong>Scan URL</strong> <a id="scan-url" target="_blank" rel="noopener"></a> <button type="button" class="btn" id="copy-scan">Copy link</button> <span id="scan-feedback" role="status"></span></div>
<p id="card-status" role="status"></p><canvas width="900" height="1260" id="brand-card" data-name="{{ $kiosk->name }}" data-business="{{ $business->name }}" data-icon="{{ $kiosk->logoUrl() }}" aria-label="Printable kiosk QR card"></canvas></section>
</main></div><script defer src="{{ asset('branding/studio.js').'?v=2' }}"></script>
@endsection
