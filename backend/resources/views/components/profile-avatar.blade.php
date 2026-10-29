@props(['user'])
<span {{ $attributes->class(['profile-avatar']) }} data-profile-avatar aria-hidden="true">
    <span>{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($user->name, 0, 1)) }}</span>
    <img data-avatar-image class="profile-avatar-image" alt="" @if($user->profile_photo_url) src="{{ $user->profile_photo_url }}" @else hidden @endif>
</span>
