<?php

use Illuminate\Support\Facades\Route;

// No web UI — this is an API-only application.
Route::get('/', function () {
    return response()->json(['status' => 'ok']);
});
