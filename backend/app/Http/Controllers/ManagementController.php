<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\DeviceToken;
use App\Models\DispatchRule;
use App\Models\IncidentCategory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ManagementController extends Controller
{
    public function dispatchRules()
    {
        Gate::authorize('admin');

        return JsonResource::collection(DispatchRule::latest()->paginate(25));
    }

    public function saveDispatch(Request $r, ?DispatchRule $rule = null)
    {
        Gate::authorize('admin');
        $d = $r->validate(['name' => 'required|string|max:180', 'category_id' => 'required|integer|exists:incident_categories,id', 'agency_id' => 'required|integer|exists:agencies,id', 'south' => 'required|numeric|between:-90,90', 'north' => 'required|numeric|between:-90,90|gt:south', 'west' => 'required|numeric|between:-180,180', 'east' => 'required|numeric|between:-180,180|gt:west', 'active' => 'required|boolean']);
        $rule ??= new DispatchRule;
        $rule->fill($d)->save();
        AuditLog::create(['actor_id' => $r->user()->id, 'action' => 'dispatch.saved', 'record_type' => 'dispatch_rule', 'record_id' => $rule->id, 'metadata' => $d]);

        return ['data' => $rule];
    }

    public function officers(Request $r)
    {
        Gate::authorize('staff');
        $q = User::where('role', 'officer')->where('active', true);
        if ($r->user()->role !== 'admin') {
            $q->where('agency_id', $r->user()->agency_id ?? -1);
        }if ($r->filled('agency_id')) {
            $q->where('agency_id', $r->integer('agency_id'));
        }

        return ['data' => $q->select(['id', 'name', 'agency_id'])->orderBy('name')->limit(500)->get()];
    }

    public function agencies()
    {
        return ['data' => Agency::where('active', true)->get()];
    }

    public function categories()
    {
        return ['data' => IncidentCategory::where('active', true)->get()];
    }

    public function users(Request $r)
    {
        Gate::authorize('admin');

        return JsonResource::collection(User::with('agency')->orderBy('id')->paginate(25));
    }

    public function saveUser(Request $r, ?User $user = null)
    {
        Gate::authorize('admin');
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($user?->id)], 'role' => 'required|in:admin,officer,citizen', 'agency_id' => 'nullable|required_if:role,officer|integer|exists:agencies,id', 'active' => 'required|boolean', 'phone' => 'nullable|string|max:30']);
        abort_if($user?->id === $r->user()->id && (! $d['active'] || $d['role'] !== 'admin'), 422, 'You cannot remove your own administrator access.');
        $new = ! $user?->exists;
        $user ??= new User;
        $emailChanged = $user->exists && $user->email !== $d['email'];
        $d['agency_id'] = $d['role'] === 'officer' ? $d['agency_id'] : null;
        DB::transaction(function () use ($user, $d, $new, $emailChanged, $r) {
            $user->forceFill($d);
            if ($new) {
                $user->password = Str::random(64);
            }if ($emailChanged) {
                $user->email_verified_at = null;
            }$user->save();
            $user->tokens()->delete();
            AuditLog::create(['actor_id' => $r->user()->id, 'action' => 'user.saved', 'record_type' => 'user', 'record_id' => $user->id, 'metadata' => ['role' => $user->role, 'active' => $user->active, 'agency_id' => $user->agency_id]]);
        });
        if ($new || $emailChanged) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return ['data' => $user];
    }

    public function resetUser(User $user)
    {
        Gate::authorize('admin');
        Password::sendResetLink(['email' => $user->email]);

        return ['data' => ['message' => 'Password reset requested.']];
    }

    public function saveAgency(Request $r, Agency $agency)
    {
        Gate::authorize('admin');
        $d = $r->validate(['name' => 'required|string|max:180', 'jurisdiction' => 'nullable|string|max:2000', 'emergency_phone' => 'nullable|string|max:50', 'active' => 'required|boolean']);
        $agency->update($d);

        return ['data' => $agency];
    }

    public function tokens(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|max:512']);
        abort_if(DeviceToken::where('token', $d['token'])->where('user_id', '!=', $r->user()->id)->exists(), 409);
        DeviceToken::firstOrCreate(['token' => $d['token']], ['user_id' => $r->user()->id]);

        return ['data' => ['message' => 'Device registered.']];
    }

    public function revokeToken(Request $r)
    {
        $d = $r->validate(['token' => 'required|string|max:512']);
        DeviceToken::where('user_id', $r->user()->id)->where('token', $d['token'])->delete();

        return ['data' => ['message' => 'Device revoked.']];
    }

    public function notifications(Request $r)
    {
        return JsonResource::collection($r->user()->unreadNotifications()->latest()->paginate(25));
    }

    public function readNotification(Request $r, string $id)
    {
        $r->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return ['data' => ['message' => 'Marked read.']];
    }

    public function audit()
    {
        Gate::authorize('admin');

        return JsonResource::collection(AuditLog::latest()->paginate(50));
    }
}
