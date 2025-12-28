<?php

use App\Models\Campaign;
use App\Models\User;

$creator = User::where('email', 'creator.premium@nexacreators.com.br')->first();
$campaigns = Campaign::all();

echo "Creator: " . $creator->email . "\n";
echo "Creator Type: " . $creator->creator_type . "\n";
echo "Creator Gender: " . $creator->gender . "\n";
echo "Creator Birth Date: " . $creator->birth_date . "\n";
echo "Creator Age: " . $creator->age . "\n";
echo "Creator State: " . $creator->state . "\n";
echo "Creator Instagram: " . $creator->instagram_handle . "\n\n";

echo "Total Campaigns: " . $campaigns->count() . "\n";

foreach ($campaigns as $campaign) {
    echo "Campaign ID: " . $campaign->id . "\n";
    echo "Title: " . $campaign->title . "\n";
    echo "Status: " . $campaign->status . "\n";
    echo "Is Active: " . ($campaign->is_active ? 'Yes' : 'No') . "\n";
    echo "Approved At: " . $campaign->approved_at . "\n";
    echo "Deadline: " . $campaign->deadline->toDateString() . "\n";
    echo "Target Creator Types: " . json_encode($campaign->target_creator_types) . "\n";
    echo "Target Genders: " . json_encode($campaign->target_genders) . "\n";
    echo "Target States: " . json_encode($campaign->target_states) . "\n";
    echo "Min Age: " . $campaign->min_age . "\n";
    echo "Max Age: " . $campaign->max_age . "\n";
    echo "--------------------------------\n";
}
