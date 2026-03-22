<?php

use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (\Illuminate\Http\Request $request) {
        return $request->user();
    });

    Route::apiResource('posts', \App\Http\Controllers\Api\ApiController::class);

    Route::get('/categories', function () {
        return \App\Models\Category::all();
    });
});
