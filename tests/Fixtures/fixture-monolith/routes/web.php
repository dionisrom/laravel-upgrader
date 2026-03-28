<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::resource('posts', \App\Http\Controllers\PostController::class);
    Route::resource('categories', \App\Http\Controllers\CategoryController::class);
});
