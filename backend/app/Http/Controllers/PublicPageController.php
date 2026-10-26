<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PublicPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PublicPageController extends Controller
{
    private function page(string $type): PublicPage
    {
        abort_unless(in_array($type, PublicPage::TYPES, true), 404);

        return PublicPage::forType($type);
    }

    public function published(string $type)
    {
        return ['data' => $this->page($type)];
    }

    public function show(string $type)
    {
        Gate::authorize('admin');

        return ['data' => $this->page($type)];
    }

    public function update(Request $request, string $type)
    {
        Gate::authorize('admin');
        $page = $this->page($type);
        $data = $request->validate([
            'eyebrow' => 'nullable|string|max:120', 'heading' => 'required|string|max:180',
            'introduction' => 'nullable|string|max:3000',
            'form_heading' => [Rule::requiredIf($type === 'contact-details'), 'nullable', 'string', 'max:180'],
            'form_introduction' => 'nullable|string|max:1000',
        ]);
        DB::transaction(function () use ($request, $page, $data, $type) {
            $before = $page->only(PublicPage::EDITABLE);
            $page->fill($data)->save();
            AuditLog::recordChange($request->user(), 'page.updated', $page, $before, $page->only(PublicPage::EDITABLE), PublicPage::LABELS[$type].' page');
        });

        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return redirect('/manage/'.$type)->with('status', 'Page updated successfully.');
        }

        return ['data' => $page, 'message' => 'Page updated successfully.'];
    }
}
