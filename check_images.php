<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Campaign\Campaign;
use App\Models\User\User;
use Illuminate\Support\Facades\Config;

// Force GCS config for testing URL generation
Config::set('filesystems.default', 'gcs');
Config::set('filesystems.disks.gcs.bucket', 'nexa-uploads-prod');

echo "--- Campaign Images ---\n";
$campaigns = Campaign::whereNotNull('logo')->orWhereNotNull('image_url')->orderBy('id', 'desc')->take(5)->get();
foreach ($campaigns as $campaign) {
    echo "ID: " . $campaign->id . "\n";
    echo "Title: " . $campaign->title . "\n";
    echo "Raw Logo: " . $campaign->getRawOriginal('logo') . "\n";
    echo "Generated Logo URL: " . $campaign->logo . "\n";
    echo "Raw Image: " . $campaign->getRawOriginal('image_url') . "\n";
    echo "Generated Image URL: " . $campaign->image_url . "\n";
    echo "-------------------\n";
}

echo "\n--- User Avatars ---\n";
$users = User::whereNotNull('avatar_url')->orderBy('id', 'desc')->take(5)->get();
foreach ($users as $user) {
    echo "ID: " . $user->id . "\n";
    echo "Name: " . $user->name . "\n";
    echo "Raw Avatar: " . $user->getRawOriginal('avatar_url') . "\n";
    echo "Generated Avatar URL: " . $user->avatar . "\n";
    echo "-------------------\n";
}
