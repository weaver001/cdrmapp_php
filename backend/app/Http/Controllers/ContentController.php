<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ContactMessage;
use App\Models\Content;
use App\Services\RichText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ContentController extends Controller
{
    public const TYPES = ['announcements', 'services', 'team-members', 'contact-details', 'faqs'];

    private function type(string $type): void
    {
        abort_unless(in_array($type, self::TYPES, true), 404);
    }

    public function published(string $type)
    {
        $this->type($type);

        return ['data' => Content::where('type', $type)->where('published', true)->latest()->limit(100)->get()];
    }

    public function contact(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:254', 'message' => 'required|string|min:10|max:5000']);
        ContactMessage::create($d);

        return response()->json(['data' => ['message' => 'Message received. This form is not monitored as an emergency hotline.']], 201);
    }

    public function index(string $type)
    {
        Gate::authorize('admin');
        $this->type($type);

        return JsonResource::collection(Content::where('type', $type)->latest()->paginate(25));
    }

    public function save(Request $r, string $type, ?Content $content = null)
    {
        Gate::authorize('admin');
        $this->type($type);
        if ($content?->exists) {
            abort_unless($content->type === $type, 404);
        }
        $d = $r->validate(['title' => 'required|string|max:180', 'body' => 'required|string|max:20000', 'published' => 'required|boolean', 'details' => 'nullable|array:position,email,latitude,longitude,phone', 'details.position' => 'nullable|string|max:120', 'details.email' => 'nullable|email', 'details.phone' => 'nullable|string|max:50', 'details.latitude' => 'nullable|numeric|between:-90,90', 'details.longitude' => 'nullable|numeric|between:-180,180']);
        // Sanitize at the trust boundary before publication.
        $d['body'] = app(RichText::class)->sanitize($d['body']);
        $d['type'] = $type;
        $content ??= new Content;
        DB::transaction(function () use ($content, $d, $r) {
            $new = ! $content->exists;
            $before = $new ? [] : $this->snapshot($content);
            $content->fill($d)->save();
            AuditLog::recordChange($r->user(), $new ? 'content.created' : 'content.updated', $content, $before, $this->snapshot($content));
        });

        return ['data' => $content];
    }

    public function remove(Request $r, string $type, Content $content)
    {
        Gate::authorize('admin');
        $this->type($type);
        abort_unless($content->type === $type, 404);
        DB::transaction(function () use ($content, $r) {
            $before = $this->snapshot($content);
            $content->delete();
            AuditLog::recordChange($r->user(), 'content.deleted', $content, $before, []);
        });

        return ['data' => ['message' => 'Moved to trash.']];
    }

    public function messages(Request $r)
    {
        Gate::authorize('admin');
        $d = $r->validate(['q' => 'nullable|string|max:100', 'trash' => 'nullable|boolean']);
        $q = ContactMessage::query();
        if ($r->boolean('trash')) {
            $q->onlyTrashed();
        }if (! empty($d['q'])) {
            $q->where(fn ($q) => $q->where('name', 'like', '%'.$d['q'].'%')->orWhere('message', 'like', '%'.$d['q'].'%'));
        }

        return JsonResource::collection($q->latest()->paginate(25));
    }

    public function messageAction(Request $r, int $id, string $action)
    {
        Gate::authorize('admin');
        $m = ContactMessage::withTrashed()->findOrFail($id);
        $before = $m->only(['name', 'email', 'message', 'deleted_at']);
        DB::transaction(function () use ($r, $m, $action, $before) {
            if ($action === 'trash') {
                $m->delete();
            } elseif ($action === 'restore') {
                $m->restore();
            } elseif ($action === 'destroy') {
                $r->validate(['confirm' => 'required|accepted']);
                abort_unless($m->trashed(), 422, 'Move to trash first.');
                $m->forceDelete();
            } else {
                abort(404);
            }
            AuditLog::recordChange($r->user(), 'message.'.$action, $m, $before, $action === 'destroy' ? [] : $m->only(['name', 'email', 'message', 'deleted_at']), 'Message from '.$m->name);
        });

        return ['data' => ['message' => 'Message updated.']];
    }

    private function snapshot(Content $content): array
    {
        return $content->only(['type', 'title', 'body', 'published', 'details']);
    }
}
