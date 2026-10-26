@extends('layout', ['title' => 'Verified successfully'])

@section('content')
<section class="panel max-w-lg mx-auto text-center" aria-labelledby="verification-result-title">
    <h1 id="verification-result-title" class="text-2xl font-bold">Verified successfully</h1>
    <p class="my-4">Your email address has been verified. You can now sign in to your account.</p>
    <a href="{{ route('login') }}" class="btn inline-block">Return to Login Page</a>
</section>
@endsection
