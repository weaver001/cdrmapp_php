@extends('layout', ['title' => 'Content management'])
@section('content')
<header class="page-heading"><p class="kicker">Public website</p><h1>Content management</h1><p class="text-slate-600 mt-2">Choose a page to update its header, introductory text, and published content.</p></header>
<div class="cms-grid">
@foreach(\App\Models\PublicPage::LABELS as $key => $label)
    @php($tone = ['services' => 'green', 'announcements' => 'orange', 'team-members' => 'burgundy', 'faqs' => 'gold', 'contact-details' => 'green'][$key])
    <article class="cms-card tone-{{ $tone }}"><div class="cms-card-body"><span class="cms-card-number" aria-hidden="true">{{ $loop->iteration }}</span><h2>{{ $label }}</h2><p class="text-sm text-slate-600 mt-2">Edit the page heading and introduction, then manage its content items.</p><p class="text-sm mt-4"><strong>{{ $counts[$key]->published_count ?? 0 }}</strong> published · <strong>{{ ($counts[$key]->total ?? 0) - ($counts[$key]->published_count ?? 0) }}</strong> drafts</p></div><a class="cms-card-footer" href="/manage/{{ $key }}">Manage {{ $label }} <span aria-hidden="true">→</span></a></article>
@endforeach
</div>
@endsection
