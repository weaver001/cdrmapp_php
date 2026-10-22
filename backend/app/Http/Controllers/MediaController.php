<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Incident;
use App\Models\IncidentAttachment;
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
        abort_unless($content->type === $type, 404);
        $disk = config('filesystems.cms_disk');
        // R2 / Laravel Cloud uses bucket visibility and rejects public ACLs.
        $path = $this->image($r)->store('content', $disk);
        abort_unless($path, 503, 'Image storage is unavailable. Please retry.');
        $old = $content->image_path;
        $oldDisk = $content->image_disk ?: 'public';
        try {
            $content->update(['image_path' => $path, 'image_disk' => $disk]);
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
        $path = $this->image($r)->store('profiles', 'local');
        $old = $r->user()->profile_photo_path;
        $r->user()->forceFill(['profile_photo_path' => $path])->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return ['data' => ['message' => 'Profile image updated.']];
    }

    public function readPhoto(Request $r)
    {
        abort_unless($r->user()->profile_photo_path, 404);

        return Storage::disk('local')->response($r->user()->profile_photo_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
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
