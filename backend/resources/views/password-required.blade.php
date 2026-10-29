@extends('layout')
@section('content')
<section class="panel max-w-lg mx-auto">
    <h1 class="text-2xl font-bold mb-4">Change your temporary password</h1>
    <p class="mb-4">Choose your own password before accessing the system. Use at least 12 characters, including letters and numbers. Email verification is not required for staff accounts.</p>
    <form method="post" action="{{ route('password.update') }}" class="grid gap-4">
        @csrf
        <label>Temporary password<input type="password" name="current_password" autocomplete="current-password" required></label>
        <label>New password<input type="password" name="password" minlength="12" autocomplete="new-password" required></label>
        <label>Confirm new password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></label>
        <button class="btn">Update password &amp; sign out</button>
    </form>
</section>
@endsection
