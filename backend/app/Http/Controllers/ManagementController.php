<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\AgencyDispatchRoute;
use App\Models\AuditLog;
use App\Models\DeviceToken;
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

        return JsonResource::collection(AgencyDispatchRoute::with('agency')->orderBy('id')->paginate(25));
    }

    public function saveDispatch(Request $r, ?AgencyDispatchRoute $rule = null)
    {
        Gate::authorize('admin');
        $d = $r->validate(['agency_id' => ['required', 'integer', Rule::exists('agencies', 'id')->whereIn('type', array_keys(Agency::DEFAULT_NAMES))], 'active' => 'required|boolean']);
        $d['agency_id'] = (int) $d['agency_id'];
        abort_if($rule?->exists && $rule->agency_id !== $d['agency_id'], 422, 'Edit the routing setting for the selected agency.');
        $rule = DB::transaction(function () use ($d, $r) {
            $rule = AgencyDispatchRoute::firstOrNew(['agency_id' => $d['agency_id']]);
            $new = ! $rule->exists;
            $before = $rule->exists ? $rule->only(['active']) : [];
            $rule->fill($d)->save();
            AuditLog::recordChange($r->user(), $new ? 'dispatch.created' : 'dispatch.updated', $rule, $before, $rule->only(['active']), $rule->agency->name);

            return $rule;
        });

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
        return ['data' => Agency::where('active', true)->inDisplayOrder()->get()];
    }

    public function categories()
    {
        return ['data' => IncidentCategory::where('active', true)->inDisplayOrder()->get()];
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
        $before = $new ? [] : $this->userSnapshot($user);
        DB::transaction(function () use ($user, $d, $new, $emailChanged, $r, $before) {
            $user->forceFill($d);
            if ($new) {
                $user->password = Str::random(64);
            }if ($emailChanged) {
                $user->email_verified_at = null;
            }$user->save();
            $user->tokens()->delete();
            AuditLog::recordChange($r->user(), $new ? 'user.created' : 'user.updated', $user, $before, $this->userSnapshot($user));
        });
        $message = $new ? 'Account created successfully.' : 'Account updated successfully.';
        if ($new || $emailChanged) {
            try {
                $status = Password::sendResetLink(['email' => $user->email]);
                $message .= $status === Password::RESET_LINK_SENT ? ' Password invitation sent.' : ' Password invitation was not sent; use Send password reset to retry.';
            } catch (\Throwable $e) {
                report($e);
                $message .= ' Password invitation could not be sent. Check email settings and use Send password reset to retry.';
            }
        }

        return ['data' => $user, 'message' => $message];
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
        DB::transaction(function () use ($r, $agency, $d) {
            $before = $agency->only(['name', 'jurisdiction', 'emergency_phone', 'active']);
            $agency->update($d);
            AuditLog::recordChange($r->user(), 'agency.updated', $agency, $before, $agency->only(['name', 'jurisdiction', 'emergency_phone', 'active']));
        });

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

        return JsonResource::collection(AuditLog::with('actor:id,name,email')->latest('id')->paginate(50));
    }

    private function userSnapshot(User $user): array
    {
        return $user->only(['name', 'email', 'role', 'active', 'agency_id']) + [
            'agency_name' => $user->agency_id ? Agency::find($user->agency_id)?->name : null,
        ];
    }
}
