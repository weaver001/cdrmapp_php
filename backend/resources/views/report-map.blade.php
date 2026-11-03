<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#005f35">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <title>Incident map · CDRMAPP</title>
    @vite(['resources/css/app.css', 'resources/js/report-map.js'])
</head>
<body>
    <main id="report-map-page" data-incident-id="{{ $incident->id }}" data-map-key="{{ config('services.google_maps.web_key') }}">
        <div id="report-map" aria-label="Selected incident location"></div>
        <aside id="report-details" aria-labelledby="report-details-title" tabindex="0">
            <h1 id="report-details-title" class="text-xl font-bold">Report details</h1>
            <div id="report-details-body" class="mt-4">Loading report details…</div>
        </aside>
        <a class="btn report-map-back" href="{{ url('/dashboard') }}" aria-label="Back to incident dashboard">Back</a>
        <p id="report-map-status" role="status">Loading incident location…</p>
    </main>
</body>
</html>
