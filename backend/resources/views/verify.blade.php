@extends('layout')
@section('content')<h1 class="text-2xl font-bold">Verify your email</h1><p class="my-4">Open the verification link sent to your email before accessing protected reports.</p><form action="/email/verification-notification" method="post">@csrf<button class="btn">Resend verification link</button></form>@endsection
