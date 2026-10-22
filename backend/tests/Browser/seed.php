<?php

use App\Models\Agency;
use App\Models\DispatchRule;
use App\Models\IncidentCategory;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local')) {
    throw new RuntimeException('Local fixtures only.');
}
$path = storage_path('app/private/browser-fixture.json');
$credentials = is_file($path) ? json_decode(file_get_contents($path), true) : [];
foreach (['admin', 'citizen', 'FIRE', 'PNP'] as $role) {
    $email = strtolower($role).'@synthetic.example.test';
    if (! isset($credentials[$role])) {
        $credentials[$role] = ['email' => $email, 'password' => Str::random(24)];
    }
    $u = User::firstOrNew(['email' => $email]);
    if ($u->exists && ! str_starts_with($u->name, 'SYNTHETIC ')) {
        throw new RuntimeException('Existing account is not a test fixture.');
    }$u->forceFill(['name' => 'SYNTHETIC '.$role, 'password' => $credentials[$role]['password'], 'role' => in_array($role, ['FIRE', 'PNP']) ? 'officer' : $role, 'agency_id' => in_array($role, ['FIRE', 'PNP']) ? Agency::where('type', $role)->value('id') : null, 'email_verified_at' => now(), 'active' => true])->save();
}
$category = IncidentCategory::where('name', 'Fire Department')->first();
$agency = Agency::where('type', 'FIRE')->first();
DispatchRule::firstOrCreate(['name' => 'SYNTHETIC browser-only rectangle'], ['category_id' => $category->id, 'agency_id' => $agency->id, 'south' => 14, 'north' => 15, 'west' => 120, 'east' => 122, 'active' => true]);
file_put_contents($path, json_encode($credentials));
chmod($path, 0600);
echo "Local synthetic accounts prepared. Credentials remain in ignored private storage.\n";
