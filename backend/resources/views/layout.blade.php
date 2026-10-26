<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#005f35"><title>{{ $title ?? 'CDRMAPP' }} · CDRMAPP</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body><a href="#main" class="sr-only focus:not-sr-only">Skip to content</a>
<header class="site-header"><div class="header-inner">
    <a href="/" class="brand"><span class="brand-mark" aria-hidden="true">C</span><span><strong class="tracking-wide">CDRMAPP</strong><small>Community information &amp; incident response</small></span></a>
    <nav aria-label="Main" class="site-nav">
        @foreach(\App\Models\PublicPage::LABELS as $key => $label)<a href="/pages/{{ $key }}">{{ $label }}</a>@endforeach
        @auth
            @if(auth()->user()->isStaff())<a href="/dashboard">Dashboard</a>@endif
            <a href="/profile">Profile</a><form method="post" action="/logout">@csrf<button>Sign out</button></form>
        @else<a href="/login">Staff sign in</a>@endauth
    </nav>
</div></header>
@if(session('status'))<p role="status" class="notice">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert" class="notice">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<div class="workspace {{ auth()->check() && auth()->user()->isStaff() ? 'workspace--staff' : 'workspace--public' }}">
@auth @if(auth()->user()->isStaff())
    <aside class="staff-sidebar print:hidden">
        <div class="staff-user"><span class="staff-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr(auth()->user()->name,0,1)) }}</span><div><p class="font-semibold text-sm">{{ auth()->user()->name }}</p><p class="text-xs text-emerald-200">{{ ucfirst(auth()->user()->role) }}</p></div></div>
        <details class="staff-menu" data-staff-menu open><summary>CDRMAPP navigation</summary><nav aria-label="Staff">
            <a href="/dashboard" @if(request()->is('dashboard')) aria-current="page" @endif>Incident dashboard</a>
            <a href="/incident-map" @if(request()->is('incident-map')) aria-current="page" @endif>Map &amp; heatmap</a>
            @if(auth()->user()->role==='admin')
                @php($cmsOpen = request()->is('manage/cms') || in_array(request()->route('module'), \App\Models\PublicPage::TYPES, true))
                <details class="cms-nav" @if($cmsOpen) open @endif><summary>Content management</summary>
                    <a href="/manage/cms" @if(request()->is('manage/cms')) aria-current="page" @endif>CMS overview</a>
                    @foreach(\App\Models\PublicPage::LABELS as $key => $label)<a href="/manage/{{ $key }}" @if(request()->is('manage/'.$key)) aria-current="page" @endif>{{ $label }}</a>@endforeach
                </details>
                @foreach(['users' => 'Users', 'agencies' => 'Agencies', 'messages' => 'Messages', 'audit' => 'Audit history'] as $key => $label)<a href="/manage/{{ $key }}" @if(request()->is('manage/'.$key)) aria-current="page" @endif>{{ $label }}</a>@endforeach
            @endif
        </nav></details>
    </aside>
@endif @endauth
<main id="main" class="workspace-main">@yield('content')</main>
</div>
<footer class="site-footer">For urgent assistance, use official emergency channels. This application does not guarantee an emergency response. Operational times: Asia/Manila.</footer>
</body></html>
