@extends('layout')
@section('content')
<div id="dashboard" data-role="{{ auth()->user()->role }}" @if($mapOnly) data-map-key="{{ config('services.google_maps.web_key') }}" @endif>
    <div class="flex justify-between gap-4 items-start">
        <div><p class="text-emerald-800 font-semibold">Operations overview</p><h1 class="text-3xl font-bold">{{ $mapOnly ? 'Incident map' : 'Incident dashboard' }}</h1><p class="text-slate-600 mt-2">{{ auth()->user()->role==='admin' ? 'All agencies' : 'Your assigned agency only' }}</p></div>
        <span id="unread" class="badge">Loading notifications…</span>
    </div>
    <p id="dashboard-status" role="status" class="my-4 text-sm">Loading the latest reports…</p>
    <div id="summary" class="grid grid-cols-3 gap-4 mb-6"></div>
    <form id="filters" class="panel grid sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
        <label>From (Manila)<input type="date" name="from"></label>
        <label>Through (Manila)<input type="date" name="to"></label>
        <label>Agency<select name="agency_id"><option value="">All authorized</option>@foreach($agencies as $agency)<option value="{{ $agency->id }}">{{ $agency->name }}</option>@endforeach</select></label>
        <label>Status<select name="status"><option value="">All statuses</option><option value="need_action">Need action</option><option value="done">Done</option></select></label>
        <label>Search<input name="q" maxlength="100" placeholder="Reference or description"></label>
        <button class="btn self-end">Apply filters</button>
    </form>
    <div class="flex flex-wrap gap-3 mb-3 print:hidden">
        <a id="switch-view" class="btn-secondary" href="{{ $mapOnly ? url('/dashboard') : route('incident.map') }}">{{ $mapOnly ? 'View reports' : 'View incident map' }}</a>
        @can('admin')
            <a id="export" class="btn-secondary" href="/api/v1/reports/export">Export CSV</a>
            <button id="print" class="btn-secondary">Print loaded reports</button>
        @endcan
        <button id="refresh" class="btn-secondary">Refresh now</button>
    </div>
    <p class="text-sm text-slate-600 mb-3">Times below are shown in Asia/Manila. Reports and map pins are paginated.@can('admin') Printing includes the current page.@endcan</p>
    @if($mapOnly)
        <section class="panel" id="map-section">
            <h2 class="text-xl font-semibold">Incident map &amp; decision support</h2>
            <p class="my-3 text-sm">Pins show the current page of filtered reports. Heatmap shows filtered validated incidents in cells with at least three events. It excludes test, duplicate, rejected, and poor-accuracy GPS reports.</p>
            <label class="flex gap-2 items-center mb-3"><input id="show-pins" type="checkbox" checked class="w-auto"> Show incident pins</label>
            <label class="flex gap-2 items-center mb-3"><input id="exploratory" type="checkbox" class="w-auto"> Exploratory: also include unverified and uncertain locations</label>
            <ul aria-label="Agency pin legend" class="flex flex-wrap gap-4 my-3 text-sm">
                <li class="flex gap-2 items-center"><img src="{{ asset('images/map/fire.svg') }}" width="20" height="24" alt=""> Fire Department (BFP) — flame</li>
                <li class="flex gap-2 items-center"><img src="{{ asset('images/map/police.svg') }}" width="20" height="24" alt=""> Crime / Police Department (PNP) — badge</li>
                <li class="flex gap-2 items-center"><img src="{{ asset('images/map/mdrrmo.svg') }}" width="20" height="24" alt=""> MDRRMO — rescue cross</li>
            </ul>
            <p class="text-sm text-slate-600 mb-3">Gray pins indicate Done. Agency-colored pins indicate Need action.</p>
            <p id="map-status" role="status" class="text-sm mb-3"></p>
            <div id="map" class="h-96 rounded-lg bg-slate-100" aria-label="Authorized incident map"></div>
            <p class="text-sm mt-3">Density: cooler colors indicate fewer reports; warmer colors indicate more. Grid: 0.01° (approximately 1 km). Density describes reported events, not future risk.</p>
        </section>
    @else
        <section class="panel overflow-x-auto">
            <h2 class="text-xl font-semibold mb-3">Response watchlist</h2>
            <table><thead><tr><th>Reference / received</th><th>Incident</th><th>Agency</th><th>Location</th><th>Status</th><th>View on map</th><th>Actions</th></tr></thead><tbody id="incidents"></tbody></table>
        </section>
    @endif
    <div class="flex gap-4 mt-4"><button id="previous" class="btn-secondary">Previous</button><span id="page" class="self-center"></span><button id="next" class="btn-secondary">Next</button></div>
    <dialog id="incident-detail" class="panel max-w-2xl w-full"><form method="dialog"><button class="btn-secondary float-right">Close</button></form><div id="detail-body"></div></dialog>
</div>
@endsection
