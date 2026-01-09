<?php

declare(strict_types=1);

use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => ['Laravel' => app()->version()]);

Route::get('/storage/{path}', function ($path) {
    if (str_starts_with($path, 'http')) {
        return redirect($path);
    }

    $filePath = storage_path('app/public/'.$path);
    if (!file_exists($filePath)) {
        abort(404);
    }

    $file = new File($filePath);

    return response()->file($filePath, ['Content-Type' => $file->getMimeType(), 'Access-Control-Allow-Origin' => '*']);
})->where('path', '.*');
