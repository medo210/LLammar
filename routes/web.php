<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return 'OK';
});

Route::get("/generator", function () {
    return response()->make(view("generator"));
});
