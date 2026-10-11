<?php

use App\Http\Controllers\PageController;
use App\Http\Controllers\PromotionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'site.home')->name('home');
Route::get('promocoes/{slug}', [PromotionController::class, 'show'])->name('promotions.show');
Route::get('paginas/{page}', PageController::class)
    ->whereIn('page', array_keys(PageController::PAGES))
    ->name('pages.show');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
