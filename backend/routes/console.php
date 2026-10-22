<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

Artisan::command('cdrmapp:create-admin {email} {--name=Local Administrator}', function () {
    if (! app()->environment(['local', 'testing'])) {
        $this->error('Local/test only.');

        return 1;
    }
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Invalid email.');

        return 1;
    }
    if (User::where('email', $email)->exists()) {
        $this->error('Account already exists.');

        return 1;
    }
    $password = Str::random(24);
    $u = new User;
    $u->forceFill(['name' => $this->option('name'), 'email' => $email, 'password' => $password, 'role' => 'admin', 'active' => true, 'email_verified_at' => now()])->save();
    $this->info('Local synthetic administrator created. Password (store securely): '.$password);
});
