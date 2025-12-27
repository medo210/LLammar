<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return 'OK';
});

Route::get("/generator", function () {
    return response()->make(view("generator"));
});

use App\Http\Controllers\CopyController;

Route::get('/copy', function () {
    return response()->make(view('copy'));
});

Route::post('/copy/generate', [CopyController::class, 'generate']);
Route::post('/copy/upload-images', [\App\Http\Controllers\CopyController::class, 'uploadImages']);

use App\Http\Controllers\CollageController;

Route::get('/collage', function () {
    return response()->make(view('collage'));
});

Route::post('/collage/generate', [CollageController::class, 'generate']);

use App\Http\Controllers\PosterController;

Route::get('/poster', function () {
    return response()->make(view('poster'));
});

Route::post('/poster/generate', [PosterController::class, 'generate']);
