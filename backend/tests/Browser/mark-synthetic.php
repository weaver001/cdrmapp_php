<?php

use App\Models\Incident;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local')) {
    throw new RuntimeException('Local test marking only.');
}
$count = 0;
foreach (Incident::where('description', 'like', 'SYNTHETIC BROWSER TEST:%')->whereIn('reporter_id', User::where('email', 'citizen@synthetic.example.test')->select('id'))->get() as $i) {
    $i->is_test = true;
    $i->save();
    $count++;
}
echo 'Marked '.$count.' local synthetic reports as test records.'.PHP_EOL;
