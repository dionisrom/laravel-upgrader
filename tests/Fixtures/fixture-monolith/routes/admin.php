<?php

use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('admin.dashboard');
    Route::resource('users', \App\Http\Controllers\Admin\UserController::class);
    Route::resource('posts', \App\Http\Controllers\Admin\PostController::class);
    Route::get('/horizon', function () {
        return redirect('/horizon/dashboard');
    })->name('admin.horizon');
});
