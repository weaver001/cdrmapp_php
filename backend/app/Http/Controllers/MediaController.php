<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Content;
use App\Models\Incident;
use App\Models\IncidentAttachment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    private function image(Request $r)
    {
        $r->validate(['image' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=8000,max_height=8000']);

        return $r->file('image');
    }

    public function content(Request $r, string $type, Content $content)
    {
        Gate::authorize('admin');
        abort_unless(in_array($type, ContentController::TYPES, true), 404);
        abort_unless($content->type === $type, 404);
        $disk = config('filesystems.cms_disk');
        // R2 / Laravel Cloud uses bucket visibility and rejects public ACLs.
        $path = $this->image($r)->store('content', $disk);
        abort_unless($path, 503, 'Image storage is unavailable. Please retry.');
        $old = $content->image_path;
        $oldDisk = $content->image_disk ?: 'public';
        try {
            DB::transaction(function () use ($r, $content, $path, $disk) {
                $before = $content->only(['image_path', 'image_disk']);
                $content->update(['image_path' => $path, 'image_disk' => $disk]);
                AuditLog::recordChange($r->user(), 'content.image_updated', $content, $before, $content->only(['image_path', 'image_disk']));
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }
        if ($old) {
            Storage::disk($oldDisk)->delete($old);
        }

        return ['data' => $content];
    }

    public function photo(Request $r)
    {
        $disk = config('filesystems.profile_disk');
        $path = $this->image($r)->store('profiles', $disk);
        abort_unless($path, 503, 'Profile image storage is unavailable. Please retry.');
        try {
            [$user, $old, $oldDisk] = DB::transaction(function () use ($r, $path, $disk) {
                $user = User::lockForUpdate()->findOrFail($r->user()->id);
                $old = $user->profile_photo_path;
                $oldDisk = $user->profile_photo_disk ?: 'local';
                $user->forceFill(['profile_photo_path' => $path, 'profile_photo_disk' => $disk])->save();

                return [$user, $old, $oldDisk];
            });
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }
        $r->user()->forceFill(['profile_photo_path' => $path, 'profile_photo_disk' => $disk]);
        if ($old) {
            rescue(fn () => Storage::disk($oldDisk)->delete($old));
        }

        return ['data' => ['message' => 'Profile image updated.', 'photo_url' => $user->profile_photo_url]];
    }

    public function readPhoto(Request $r)
    {
        abort_unless($r->user()->profile_photo_path, 404);
        $disk = Storage::disk($r->user()->profile_photo_disk ?: 'local');
        abort_unless($disk->exists($r->user()->profile_photo_path), 404);

        return $disk->response($r->user()->profile_photo_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function attachment(Request $r, Incident $incident)
    {
        Gate::authorize('view', $incident);
        abort_unless($r->user()->id === $incident->reporter_id || $r->user()->isStaff(), 403);
        $file = $this->image($r);
        abort_if($incident->attachments()->count() >= 5, 422, 'Maximum five attachments per report.');
        $path = $file->store('evidence', 'local');
        try {
            $a = DB::transaction(function () use ($incident, $file, $path) {
                $i = Incident::lockForUpdate()->findOrFail($incident->id);
                abort_if($i->attachments()->count() >= 5, 422, 'Maximum five attachments per report.');

                return IncidentAttachment::create(['incident_id' => $i->id, 'path' => $path, 'mime' => $file->getMimeType(), 'size' => $file->getSize()]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return response()->json(['data' => ['id' => $a->id, 'mime' => $a->mime, 'size' => $a->size]], 201);
    }

    public function readAttachment(Incident $incident, IncidentAttachment $attachment)
    {
        Gate::authorize('view', $incident);
        abort_unless($attachment->incident_id === $incident->id, 404);

        return Storage::disk('local')->download($attachment->path, 'incident-image-'.$attachment->id, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
