@extends('layout')
@section('content')<section class="panel max-w-lg mx-auto"><h1 class="text-2xl font-bold mb-5">{{ $mode==='login'?'Staff sign in':($mode==='forgot'?'Reset your password':'Choose a new password') }}</h1>
<form method="post" action="{{ $mode==='login'?'/login':($mode==='forgot'?'/forgot-password':'/reset-password') }}" class="grid gap-5">@csrf
@if($mode==='reset')<input type="hidden" name="token" value="{{ $token }}">@endif
<label>Email<input type="email" name="email" autocomplete="username" required value="{{ old('email',request('email')) }}"></label>
@if($mode!=='forgot')<label>Password<input type="password" name="password" required autocomplete="{{ $mode==='login'?'current-password':'new-password' }}" @if($mode==='reset') minlength="12" @endif></label>@endif
@if($mode==='reset')<label>Confirm password<input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>@endif
@if($mode==='login')<label class="flex gap-2 items-center"><input class="w-auto" type="checkbox" name="remember" value="1"> Remember me on this device</label>@endif
<button class="btn">{{ $mode==='login'?'Sign in':'Submit' }}</button></form>@if($mode==='login')<a href="/forgot-password" class="block mt-4 underline">Forgot password?</a>@endif</section>@endsection
