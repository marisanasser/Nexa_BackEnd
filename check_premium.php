<?php

$user = \App\Models\User::where('email', 'creator_test@nexa.com')->first();
if ($user) {
    echo 'User: '.$user->name."\n";
    echo 'Premium: '.($user->has_premium ? 'Yes' : 'No')."\n";
    echo 'Expires: '.$user->premium_expires_at."\n";
} else {
    echo "User not found\n";
}
