<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:254|unique:users', 'date_of_birth' => 'nullable|date|before:today', 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()]]);
        $u = User::create($d);
        event(new Registered($u));

        return response()->json(['data' => ['user' => $u, 'message' => 'Check your email to verify your account.']], 201);
    }

    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string', 'device_name' => 'nullable|string|max:100']);
        $u = User::where('email', $d['email'])->first();
        if (! $u || ! Hash::check($d['password'], $u->password) || ! $u->active) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials or inactive account.']);
        }

        return ['data' => ['user' => $u, 'token' => $u->createToken($d['device_name'] ?? 'mobile', ['*'], now()->addDay())->plainTextToken, 'expires_at' => now()->addDay()->toISOString()]];
    }

    public function logout(Request $r)
    {
        if ($r->user()->currentAccessToken() instanceof PersonalAccessToken) {
            $r->user()->currentAccessToken()->delete();
        } else {
            Auth::guard('web')->logout();
            if ($r->hasSession()) {
                $r->session()->invalidate();
                $r->session()->regenerateToken();
            }
        }

        return ['data' => ['message' => 'Signed out.']];
    }

    public function me(Request $r)
    {
        return ['data' => $r->user()];
    }

    public function profile(Request $r)
    {
        $d = $r->validate(['name' => 'sometimes|required|string|max:120', 'date_of_birth' => 'nullable|date|before:today', 'phone' => 'nullable|string|max:30']);
        $r->user()->update($d);

        return ['data' => $r->user()->fresh()];
    }

    public function changePassword(Request $r)
    {
        $d = $r->validate(['current_password' => 'required|current_password:sanctum', 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()]]);
        DB::transaction(function () use ($r, $d) {
            $user = User::lockForUpdate()->findOrFail($r->user()->id);
            if (! Hash::check($d['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            if ($user->must_change_password && Hash::check($d['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'Choose a new password different from your temporary password.']);
            }
            $user->forceFill(['password' => $d['password'], 'remember_token' => Str::random(60), 'must_change_password' => false])->save();
            $this->revokePasswordSessions($user);
        });
        $this->signOutBrowser($r);

        if (! $r->is('api/*') && ! $r->expectsJson()) {
            return redirect()->route('login');
        }

        return ['data' => ['message' => 'Password updated. Please sign in again.', 'redirect' => route('login', [], false)]];
    }

    public function changeEmail(Request $r)
    {
        $d = $r->validate(['current_password' => 'required|current_password:sanctum', 'email' => 'required|email|max:254|unique:users']);
        $r->user()->forceFill(['email' => $d['email'], 'email_verified_at' => null])->save();
        $r->user()->tokens()->delete();
        if (! $r->user()->hasVerifiedEmail()) {
            $r->user()->sendEmailVerificationNotification();
        }

        return ['data' => ['message' => $r->user()->isStaff() ? 'Email updated. Sign in again.' : 'Check your new email and sign in again.']];
    }

    public function resend(Request $r)
    {
        if ($r->user()->isStaff()) {
            $message = 'Email verification is not required for staff accounts.';
            if (! $r->is('api/*') && ! $r->expectsJson()) {
                return redirect($r->user()->must_change_password ? route('password.required') : '/dashboard')->with('status', $message);
            }

            return ['data' => ['message' => $message]];
        }
        if (! $r->user()->hasVerifiedEmail()) {
            $r->user()->sendEmailVerificationNotification();
        }

        if (! $r->is('api/*') && ! $r->expectsJson()) {
            return redirect()->route('verification.notice')->with('status', $r->user()->hasVerifiedEmail()
                ? 'Your email is already verified. You may sign in.'
                : 'Verification link sent. Please check your inbox.');
        }

        return ['data' => ['message' => 'Verification link requested.']];
    }

    public function verify(Request $r, string $id, string $hash)
    {
        $u = User::findOrFail($id);
        abort_unless(hash_equals(sha1($u->getEmailForVerification()), $hash), 403);
        if (! $u->hasVerifiedEmail()) {
            $u->markEmailAsVerified();
            event(new Verified($u));
        }

        if (! $r->expectsJson()) {
            return view('verification-result');
        }

        return ['data' => ['message' => 'Email verified. You may return to the app.']];
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        Password::sendResetLink($r->only('email'));

        return ['data' => ['message' => 'If this account exists, a reset link has been sent.']];
    }

    public function reset(Request $r)
    {
        $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function (User $u, string $password) {
            if ($u->must_change_password && Hash::check($password, $u->password)) {
                throw ValidationException::withMessages(['password' => 'Choose a new password different from your temporary password.']);
            }
            DB::transaction(function () use ($u, $password) {
                $u->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'must_change_password' => false])->save();
                $this->revokePasswordSessions($u);
            });
            event(new PasswordReset($u));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        $this->signOutBrowser($r);

        return ['data' => ['message' => 'Password updated. Please sign in again.', 'redirect' => route('login', [], false)]];
    }

    private function revokePasswordSessions(User $user): void
    {
        $user->tokens()->delete();
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)->delete();
        }
    }

    private function signOutBrowser(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }
        Auth::guard('web')->logout();
        // Sanctum may cache the web user for this request. Clear it before the
        // database session handler attributes the new guest session at response time.
        Auth::guard('sanctum')->forgetUser();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash('status', 'Password updated. Please sign in again.');
    }
}
