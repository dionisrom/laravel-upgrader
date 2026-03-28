<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/contact', function () {
        return view('contact');
    })->name('contact');

    Route::get('/data', function () {
        return view('data-table');
    })->name('data');
});
