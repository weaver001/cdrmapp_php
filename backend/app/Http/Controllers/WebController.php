<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\ContactMessage;
use App\Models\Content;
use App\Models\IncidentCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;

class WebController extends Controller
{
    public function home()
    {
        return view('public', ['title' => 'Community preparedness starts with clear information', 'type' => 'infographics', 'items' => Content::where('published', true)->where('type', 'infographics')->latest()->limit(10)->get()]);
    }

    public function page(string $type)
    {
        abort_unless(in_array($type, ContentController::TYPES, true), 404);

        return view('public', ['title' => ucwords(str_replace('-', ' ', $type)), 'type' => $type, 'items' => Content::where('published', true)->where('type', $type)->latest()->limit(100)->get()]);
    }

    public function contact(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:254', 'message' => 'required|string|min:10|max:5000']);
        ContactMessage::create($d);

        return back()->with('status', 'Message received. This form is not an emergency hotline.');
    }

    public function login(Request $r)
    {
        $d = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($d + ['active' => true], $r->boolean('remember'))) {
            return back()->withErrors(['email' => 'Invalid credentials or inactive account.'])->onlyInput('email');
        }
        if (! $r->user()->isStaff()) {
            Auth::logout();

            return back()->withErrors(['email' => 'Use the citizen application for your account.']);
        }
        $r->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/');
    }

    public function dashboard()
    {
        Gate::authorize('staff');

        return view('dashboard', ['agencies' => Agency::all(), 'categories' => IncidentCategory::all()]);
    }

    public function manage(string $module)
    {
        Gate::authorize('admin');
        abort_unless(in_array($module, array_merge(ContentController::TYPES, ['users', 'messages', 'agencies', 'audit', 'dispatch-rules']), true), 404);

        return view('manage', ['module' => $module, 'agencies' => Agency::all(), 'categories' => IncidentCategory::all()]);
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        Password::sendResetLink($r->only('email'));

        return back()->with('status', 'If the account exists, a reset link has been sent.');
    }

    public function reset(Request $r)
    {
        app(AuthController::class)->reset($r);

        return redirect('/login')->with('status', 'Password reset. Please sign in.');
    }
}
