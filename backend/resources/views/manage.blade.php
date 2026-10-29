@extends('layout')
@section('content')
@if($page)
    <header class="page-heading"><p class="kicker">Content management / {{ \App\Models\PublicPage::LABELS[$module] }}</p><h1>{{ \App\Models\PublicPage::LABELS[$module] }}</h1><p class="text-slate-600 mt-2">Edit this page's header and introductory text, then manage the content items below.</p></header>
    <nav class="cms-tabs" aria-label="CMS pages"><a href="/manage/cms">CMS overview</a>@foreach(\App\Models\PublicPage::LABELS as $key => $label)<a href="/manage/{{ $key }}" @if($key===$module) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>
    <details class="panel mb-6" open><summary class="section-heading">Page header &amp; introduction</summary>
        <p class="text-sm text-slate-600 mb-4">Changes here appear on the public page when saved. Text is shown as written; line breaks are preserved.</p>
        <form id="page-editor" class="grid gap-4" method="post" action="{{ route('cms.page.update', ['type' => $module]) }}" data-endpoint="/api/v1/management/pages/{{ $module }}">@csrf @method('PATCH')
            <label>Small header label<input name="eyebrow" maxlength="120" value="{{ $page->eyebrow }}"></label>
            <label>Page heading<input name="heading" required maxlength="180" value="{{ $page->heading }}"></label>
            <label>Introductory paragraph<textarea name="introduction" maxlength="3000">{{ $page->introduction }}</textarea></label>
            @if($module==='contact-details')
                <label>Contact form heading<input name="form_heading" required maxlength="180" value="{{ $page->form_heading }}"></label>
                <label>Contact form paragraph<textarea name="form_introduction" maxlength="1000">{{ $page->form_introduction }}</textarea></label>
            @endif
            <div class="flex flex-wrap gap-3"><button class="btn">Save page</button><a class="btn-secondary" href="/pages/{{ $module }}" target="_blank" rel="noopener">View public page</a></div>
            <p id="page-status" role="status" aria-live="polite"></p>
        </form>
    </details>
@endif
<div id="management" data-module="{{ $module }}" data-current-user-id="{{ auth()->id() }}">
    <h{{ $page ? '2' : '1' }} class="{{ $page ? 'section-heading' : 'text-3xl font-bold mb-4' }}">{{ $page ? 'Content items' : 'Manage '.ucwords(str_replace('-',' ',$module)) }}</h{{ $page ? '2' : '1' }}>
    <p id="form-status" class="my-4" role="status"></p>
    @if($module==='users')
        <form id="user-filters" class="panel grid sm:grid-cols-3 gap-4 mb-5">
            <label>Account type<select name="account_type"><option value="all">All accounts</option><option value="officer">All officers</option><option value="admin">Administrator</option><option value="citizen">Citizen</option><option value="fire">Fire Department (BFP)</option><option value="crime">Crime / Police Department (PNP)</option><option value="mdrrmo">MDRRMO</option></select></label>
            <label>Account status<select name="activity"><option value="all">Active and inactive</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
            <button class="btn self-end">Apply filters</button>
        </form>
    @endif
    @if(in_array($module,\App\Http\Controllers\ContentController::TYPES))
        <form id="editor" class="panel grid gap-4 mb-6" data-endpoint="/api/v1/management/content/{{ $module }}"><input type="hidden" name="id">
            <label>Image (JPEG, PNG or WebP, up to 5 MB)<input type="file" name="image" accept="image/jpeg,image/png,image/webp"></label>
            <label>Title<input name="title" maxlength="180" required></label>
            <label>Description / answer<textarea name="body" maxlength="20000" required></textarea></label>
            @if($module==='team-members')<label>Position<input name="details.position"></label><label>Public contact email<input name="details.email" type="email"></label>@endif
            @if($module==='contact-details')<label>Public phone<input name="details.phone"></label><label>Map latitude<input name="details.latitude" type="number" step="any" min="-90" max="90"></label><label>Map longitude<input name="details.longitude" type="number" step="any" min="-180" max="180"></label>@endif
            <p class="text-sm text-slate-600">Descriptions support sanitized paragraphs, headings, lists, emphasis, and links.</p>
            <label class="flex gap-2 items-center"><input type="checkbox" name="published" class="w-auto"> Publish publicly</label>
            <div class="flex gap-4"><button class="btn">Save content</button><button type="reset" class="btn-secondary">Clear Field</button></div>
        </form>
    @elseif($module==='users')
        <form id="editor" class="panel grid sm:grid-cols-2 gap-4 mb-6" data-endpoint="/api/v1/management/users"><input type="hidden" name="id">
            <label>Full name<input name="name" required></label><label>Email<input name="email" type="email" required></label>
            <label>Role<select name="role" required><option value="">Select role</option><option value="officer">Officer</option><option value="admin">Administrator</option><option value="citizen">Citizen</option></select></label>
            <label>Agency<select name="agency_id"><option value="">None</option>@foreach($agencies as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></label>
            <label class="flex gap-2 items-center"><input type="checkbox" name="active" checked class="w-auto"> Active account</label>
            <div data-staff-passwords hidden class="sm:col-span-2 grid gap-4">
                <label>Temporary password<input type="password" name="initial_password" minlength="12" autocomplete="new-password" disabled></label>
                <label>Confirm temporary password<input type="password" name="initial_password_confirmation" minlength="12" autocomplete="new-password" disabled></label>
                <p class="text-sm text-slate-600">Use at least 12 characters with letters and numbers. Share this password securely. Staff must replace it on first sign-in. Leave blank when editing to keep the existing password.</p>
            </div>
            <p data-account-delivery class="text-sm text-slate-600 sm:col-span-2">Citizens receive a password invitation and must verify their email. Staff accounts do not require email verification.</p>
            <button class="btn">Save account</button><button type="reset" class="btn-secondary">Clear Field</button>
        </form>
    @elseif($module==='messages')
        <label class="block mb-4"><input id="trash" type="checkbox" class="w-auto"> Show trashed messages</label>
    @endif
    <section id="records" class="grid gap-4"></section>
    <div class="flex gap-4 mt-5"><button id="manage-prev" class="btn-secondary">Previous</button><span id="manage-page"></span><button id="manage-next" class="btn-secondary">Next</button></div>
    @if($module==='users')
        <dialog id="staff-reset-confirm" class="panel max-w-lg w-full" aria-labelledby="staff-reset-title">
            <form id="staff-reset-form" class="grid gap-4">
                <h2 id="staff-reset-title" class="text-xl font-bold">Confirm password reset</h2>
                <p id="staff-reset-account" class="break-all"></p>
                <p>This sends a reset email and requires the user to update their password before accessing the system. For a staff account without an inbox, use Edit to set a temporary password instead.</p>
                <p id="staff-reset-status" role="alert"></p>
                <div class="flex gap-3"><button id="staff-reset-submit" type="submit" class="btn">Confirm</button><button id="staff-reset-exit" type="button" class="btn-secondary">Exit</button></div>
            </form>
        </dialog>
    @endif
</div>
@endsection
