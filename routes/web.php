<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

Route::get('/debug-visibility', function() {
    $creator = \App\Models\User::where('email', 'creator.premium@nexacreators.com.br')->first();
    $campaigns = \App\Models\Campaign::all();
    
    return response()->json([
        'creator' => $creator,
        'campaigns' => $campaigns->map(function($c) {
            return [
                'id' => $c->id,
                'title' => $c->title,
                'status' => $c->status,
                'is_active' => $c->is_active,
                'approved_at' => $c->approved_at,
                'deadline' => $c->deadline,
                'target_genders' => $c->target_genders,
                'target_creator_types' => $c->target_creator_types,
                'min_age' => $c->min_age,
                'max_age' => $c->max_age,
            ];
        })
    ]);
});

Route::get('/storage/{path}', function ($path) {
    $filePath = storage_path('app/public/'.$path);

    if (! file_exists($filePath)) {
        abort(404);
    }

    $file = new \Illuminate\Http\File($filePath);
    $mimeType = $file->getMimeType();

    return response()->file($filePath, [
        'Content-Type' => $mimeType,
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin',
        'Access-Control-Allow-Credentials' => 'true',
    ]);
})->where('path', '.*');
