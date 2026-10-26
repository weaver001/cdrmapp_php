@extends('layout')
@section('content')
<section class="page-hero">
    @if($page->eyebrow)<p class="kicker">{{ $page->eyebrow }}</p>@endif
    <h1>{{ $page->heading }}</h1>
    @if($page->introduction)<p class="mt-4 whitespace-pre-line">{{ $page->introduction }}</p>@endif
</section>
<section aria-label="Published information" class="grid md:grid-cols-2 gap-6">
@forelse($items as $item)
    <article class="panel">
        @if($item->image_path)<img src="{{ $item->image_url }}" alt="" class="rounded-md mb-4 max-h-72">@endif
        <h2 class="text-xl font-semibold">{{ $item->title }}</h2>
        <div class="prose mt-3">{!! app(\App\Services\RichText::class)->sanitize($item->body) !!}</div>
        @foreach(($item->details ?? []) as $key => $value)
            @if($value !== null)<p class="text-sm mt-2">{{ ucwords($key) }}: {{ $value }}</p>@endif
        @endforeach
        @if($type==='contact-details' && isset($item->details['latitude'],$item->details['longitude']))
            <div data-contact-map data-key="{{ config('services.google_maps.web_key') }}" data-latitude="{{ $item->details['latitude'] }}" data-longitude="{{ $item->details['longitude'] }}" class="h-72 bg-slate-100 mt-4" aria-label="Official contact map">@unless(config('services.google_maps.web_key'))Google Maps is not configured. Use the published address and coordinates.@endunless</div>
        @endif
    </article>
@empty
    <p class="panel text-slate-600">No published information yet. Please check back for official updates.</p>
@endforelse
</section>
@if($type==='contact-details')
    <section class="panel mt-8"><h2 class="text-xl font-semibold">{{ $page->form_heading }}</h2>
        @if($page->form_introduction)<p class="my-3 whitespace-pre-line">{{ $page->form_introduction }}</p>@endif
        <form method="post" action="/contact" class="grid gap-4 max-w-xl">@csrf
            <label>Full name<input name="name" required maxlength="120" autocomplete="name" value="{{ old('name') }}"></label>
            <label>Email<input type="email" name="email" required autocomplete="email" value="{{ old('email') }}"></label>
            <label>Message<textarea name="message" required minlength="10" maxlength="5000">{{ old('message') }}</textarea></label>
            <button class="btn">Send message</button>
        </form>
    </section>
@endif
@endsection
