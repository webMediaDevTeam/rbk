<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->file(public_path('index.html'));
});

Route::get('/static/{path?}', function (?string $path = null) {
    $filePath = public_path('static/' . ($path ?: 'index.html'));

    if (!File::exists($filePath)) {
        $filePath = public_path('static/index.html');
    }

    $mime = match (pathinfo($filePath, PATHINFO_EXTENSION)) {
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        default => 'application/octet-stream',
    };

    return response()->file($filePath, ['Content-Type' => $mime]);
})->where('path', '.*');

// SPA catch-all: serve index.html for all non-API, non-static routes
Route::get('/{any}', function () {
    return response()->file(public_path('index.html'));
})->where('any', '^(?!api|static|build|favicon).*');
