<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\DeviceToken;
use App\Models\IncidentCategory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class ManagementController extends Controller
{
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
        $filters = $r->validate(['account_type' => ['nullable', Rule::in(['all', 'officer', 'admin', 'citizen', 'fire', 'crime', 'mdrrmo'])], 'activity' => ['nullable', Rule::in(['all', 'active', 'inactive'])], 'page' => 'nullable|integer|min:1']);
        $query = User::with('agency')->orderBy('id');
        $type = $filters['account_type'] ?? 'all';
        $agency = ['fire' => 'FIRE', 'crime' => 'PNP', 'mdrrmo' => 'MDRRMO'][$type] ?? null;
        if ($agency) {
            $query->where('role', 'officer')->whereHas('agency', fn ($q) => $q->where('type', $agency));
        } elseif ($type !== 'all') {
            $query->where('role', $type);
        }
        if (($filters['activity'] ?? 'all') !== 'all') {
            $query->where('active', $filters['activity'] === 'active');
        }

        return JsonResource::collection($query->paginate(25)->withQueryString());
    }

    public function saveUser(Request $r, ?User $user = null)
    {
        Gate::authorize('admin');
        $new = ! $user?->exists;
        $staffRole = in_array($r->input('role'), ['admin', 'officer'], true);
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($user?->id)], 'role' => 'required|in:admin,officer,citizen', 'agency_id' => 'nullable|required_if:role,officer|integer|exists:agencies,id', 'active' => 'required|boolean', 'phone' => 'nullable|string|max:30',
            'initial_password' => [Rule::prohibitedIf(! $staffRole || $user?->id === $r->user()->id), Rule::requiredIf($new && $staffRole), 'nullable', 'string', 'confirmed', PasswordRule::min(12)->letters()->numbers()],
            'initial_password_confirmation' => 'nullable|string',
        ]);
        $temporaryPassword = $d['initial_password'] ?? null;
        unset($d['initial_password'], $d['initial_password_confirmation']);
        abort_if($user?->id === $r->user()->id && (! $d['active'] || $d['role'] !== 'admin'), 422, 'You cannot remove your own administrator access.');
        $user ??= new User;
        $emailChanged = $user->exists && $user->email !== $d['email'];
        $d['agency_id'] = $d['role'] === 'officer' ? $d['agency_id'] : null;
        $before = $new ? [] : $this->userSnapshot($user);
        DB::transaction(function () use ($user, $d, $new, $emailChanged, $r, $before, $temporaryPassword) {
            $user->forceFill($d);
            if ($temporaryPassword !== null) {
                $user->password = $temporaryPassword;
                $user->must_change_password = true;
                $user->remember_token = Str::random(60);
            } elseif ($new) {
                $user->password = Str::random(64);
            }
            if ($d['role'] === 'citizen') {
                $user->must_change_password = false;
            }
            if ($emailChanged) {
                $user->email_verified_at = null;
            }$user->save();
            $user->tokens()->delete();
            if ($temporaryPassword !== null && config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
            AuditLog::recordChange($r->user(), $new ? 'user.created' : 'user.updated', $user, $before, $this->userSnapshot($user));
        });
        $message = $new ? 'Account created successfully.' : 'Account updated successfully.';
        if ($staffRole) {
            $message .= ' No email verification is required.';
            if ($temporaryPassword !== null) {
                $message .= ' Share the temporary password securely. The user must change it on first sign-in.';
            }
        } elseif ($new) {
            try {
                $status = Password::sendResetLink(['email' => $user->email]);
                $message .= $status === Password::RESET_LINK_SENT ? ' Password invitation sent.' : ' Password invitation was not sent. The citizen can request a password reset in the Android app.';
            } catch (\Throwable $e) {
                report($e);
                $message .= ' Password invitation could not be sent. The citizen can request a password reset in the Android app.';
            }
        } elseif ($emailChanged) {
            $message .= ' The citizen must verify the new email in the Android app. Password resets are requested there.';
        }

        return ['data' => $user, 'message' => $message];
    }

    public function resetUser(Request $r, User $user)
    {
        Gate::authorize('admin');
        abort_unless(in_array($user->role, ['admin', 'officer'], true), 403, 'Citizens must request password resets in the Android app.');
        $r->validate(['confirm' => 'required|accepted']);
        DB::transaction(function () use ($r, $user) {
            $target = User::lockForUpdate()->findOrFail($user->id);
            abort_unless(in_array($target->role, ['admin', 'officer'], true), 403, 'Citizens must request password resets in the Android app.');
            $status = Password::sendResetLink(['email' => $target->email]);
            abort_unless($status === Password::RESET_LINK_SENT, 422, __($status));
            $before = ['must_change_password' => $target->must_change_password];
            $target->forceFill(['must_change_password' => true, 'remember_token' => Str::random(60)])->save();
            $target->tokens()->delete();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))->where('user_id', $target->id)->delete();
            }
            AuditLog::recordChange($r->user(), 'user.password_reset_requested', $target, $before, ['must_change_password' => true], $target->name);
        });
        $self = $r->user()->id === $user->id;
        if ($self && $r->hasSession()) {
            Auth::guard('web')->logout();
            Auth::guard('sanctum')->forgetUser();
            $r->session()->invalidate();
            $r->session()->regenerateToken();
        }

        return ['data' => ['message' => 'Password reset requested. The user must update their password before accessing the system.'] + ($self ? ['redirect' => '/login'] : [])];
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
        return $user->only(['name', 'email', 'role', 'active', 'agency_id', 'must_change_password']) + [
            'agency_name' => $user->agency_id ? Agency::find($user->agency_id)?->name : null,
        ];
    }
}
