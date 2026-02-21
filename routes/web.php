<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SentimenController;

Route::post('/sentimen/csv', [SentimenController::class, 'csv'])->name('sentimen.csv');
Route::post('/sentimen/single', [SentimenController::class, 'single'])->name('sentimen.single');
Route::post('/sentimen/link', [SentimenController::class, 'link'])->name('sentimen.link');

Route::get('/', function () {
    return redirect()->route('sentimen.index');
});

// Routing CRUD Sentimen
Route::resource('sentimen', SentimenController::class);