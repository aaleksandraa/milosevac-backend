<?php

use App\Http\Controllers\PublicPortalController;
use App\Http\Controllers\WeatherController;
use Illuminate\Support\Facades\Route;

Route::get('/weather', [WeatherController::class, 'json'])->name('weather.json');
Route::get('/content', [PublicPortalController::class, 'apiContent'])->name('content');
Route::get('/content/{post:slug}', [PublicPortalController::class, 'apiShowPost'])->name('content.show');
Route::get('/fk-posavina', [PublicPortalController::class, 'apiFkPosavina'])->name('fk-posavina');
Route::get('/fk-posavina/matches/{match:slug}', [PublicPortalController::class, 'apiShowMatch'])->name('matches.show');
