<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

$campaignId = 137;

$campaign = DB::table('campaigns')->where('id', $campaignId)->first();
$chatRooms = DB::table('chat_rooms')->where('campaign_id', $campaignId)->get();
$chatIds = $chatRooms->pluck('id')->map(fn($v)=>(int)$v)->all();
$roomIds = $chatRooms->pluck('room_id')->filter()->values()->all();
$offers = DB::table('offers')->where('campaign_id', $campaignId)->get();
$offerIds = $offers->pluck('id')->map(fn($v)=>(int)$v)->all();
$contracts = empty($offerIds) ? collect() : DB::table('contracts')->whereIn('offer_id', $offerIds)->get();
$contractIds = $contracts->pluck('id')->map(fn($v)=>(int)$v)->all();
$messages = empty($chatIds) ? collect() : DB::table('messages')->whereIn('chat_room_id', $chatIds)->get();

$notificationsQuery = DB::table('notifications');
$notificationsQuery->where(function($q) use ($roomIds, $campaignId, $contractIds): void {
    foreach ($roomIds as $roomId) {
        $q->orWhereRaw("(data->>'chat_room_id') = ?", [$roomId]);
    }
    $q->orWhereRaw("(data->>'campaign_id') = ?", [(string)$campaignId]);
    foreach ($contractIds as $contractId) {
        $q->orWhereRaw("(data->>'contract_id') = ?", [(string)$contractId]);
    }
});
$notifications = $notificationsQuery->get();

$report = [
    'generated_at_utc' => gmdate('Y-m-d H:i:s'),
    'mode' => 'apply',
    'target_campaign_id' => $campaignId,
    'before' => [
        'campaign' => $campaign,
        'chat_rooms_count' => $chatRooms->count(),
        'offers_count' => $offers->count(),
        'contracts_count' => $contracts->count(),
        'messages_count' => $messages->count(),
        'notifications_count' => $notifications->count(),
        'chat_room_ids' => $chatIds,
        'offer_ids' => $offerIds,
        'contract_ids' => $contractIds,
        'room_ids' => $roomIds,
    ],
    'deleted' => [],
    'success' => false,
];

DB::beginTransaction();

try {
    $deletedNotifications = 0;
    if ($notifications->count() > 0) {
        $deletedNotifications = DB::table('notifications')->whereIn('id', $notifications->pluck('id')->all())->delete();
    }

    $deletedMessages = 0;
    if (!empty($chatIds)) {
        $deletedMessages = DB::table('messages')->whereIn('chat_room_id', $chatIds)->delete();
    }

    $deletedContracts = 0;
    if (!empty($offerIds)) {
        $deletedContracts = DB::table('contracts')->whereIn('offer_id', $offerIds)->delete();
    }

    $deletedOffers = 0;
    if (!empty($offerIds)) {
        $deletedOffers = DB::table('offers')->whereIn('id', $offerIds)->delete();
    }

    $deletedChatRooms = 0;
    if (!empty($chatIds)) {
        $deletedChatRooms = DB::table('chat_rooms')->whereIn('id', $chatIds)->delete();
    }

    $deletedCampaigns = DB::table('campaigns')->where('id', $campaignId)->delete();

    DB::commit();

    $report['deleted'] = [
        'notifications' => $deletedNotifications,
        'messages' => $deletedMessages,
        'contracts' => $deletedContracts,
        'offers' => $deletedOffers,
        'chat_rooms' => $deletedChatRooms,
        'campaigns' => $deletedCampaigns,
    ];
    $report['success'] = true;
} catch (Throwable $e) {
    DB::rollBack();
    $report['success'] = false;
    $report['error'] = $e->getMessage();
}

$after = [
    'campaign_exists' => DB::table('campaigns')->where('id', $campaignId)->exists(),
    'chat_rooms_remaining' => empty($chatIds) ? 0 : DB::table('chat_rooms')->whereIn('id', $chatIds)->count(),
    'offers_remaining' => empty($offerIds) ? 0 : DB::table('offers')->whereIn('id', $offerIds)->count(),
    'contracts_remaining' => empty($contractIds) ? 0 : DB::table('contracts')->whereIn('id', $contractIds)->count(),
    'messages_remaining' => empty($chatIds) ? 0 : DB::table('messages')->whereIn('chat_room_id', $chatIds)->count(),
    'notifications_remaining' => $notifications->isEmpty() ? 0 : DB::table('notifications')->whereIn('id', $notifications->pluck('id')->all())->count(),
];

$report['after'] = $after;

$dir = storage_path('app/qa-reports/recovery-luciano');
File::ensureDirectoryExists($dir);
$path = $dir . DIRECTORY_SEPARATOR . 'purge_test_campaign_137_' . gmdate('Ymd_His') . '.json';
File::put($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo json_encode([
    'success' => $report['success'],
    'before' => $report['before'],
    'deleted' => $report['deleted'] ?? [],
    'after' => $report['after'],
    'report_path' => $path,
], JSON_UNESCAPED_UNICODE);
