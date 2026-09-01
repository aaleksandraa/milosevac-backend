<?php

use App\Http\Controllers\Api\GeoMapController;
use App\Http\Controllers\Api\GeoMunicipalityController;
use App\Http\Controllers\Api\GeoPlaceController;
use App\Http\Controllers\Api\GeoSettlementController;
use App\Http\Controllers\PublicPortalController;
use App\Http\Controllers\WeatherController;
use Illuminate\Support\Facades\Route;

Route::get('/weather', [WeatherController::class, 'json'])->name('weather.json');
Route::get('/ads', [PublicPortalController::class, 'apiAds'])->name('ads');
Route::get('/outages', [PublicPortalController::class, 'apiOutages'])->name('outages');
Route::get('/content', [PublicPortalController::class, 'apiContent'])->name('content');
Route::get('/content/{post:slug}', [PublicPortalController::class, 'apiShowPost'])->name('content.show');
Route::get('/fk-posavina', [PublicPortalController::class, 'apiFkPosavina'])->name('fk-posavina');
Route::get('/fk-posavina/matches/{match:slug}', [PublicPortalController::class, 'apiShowMatch'])->name('matches.show');

Route::get('/geo/map/settlements', [GeoMapController::class, 'settlements'])->name('geo.map.settlements');
Route::get('/geo/map/places', [GeoMapController::class, 'places'])->name('geo.map.places');
Route::get('/geo/places', [GeoPlaceController::class, 'index'])->name('geo.places.index');
Route::get('/geo/municipalities', [GeoMunicipalityController::class, 'index'])->name('geo.municipalities.index');
Route::get('/geo/municipalities/{municipality}', [GeoMunicipalityController::class, 'show'])->name('geo.municipalities.show');
Route::get('/geo/municipalities/{municipality}/settlements', [GeoSettlementController::class, 'index'])->name('geo.settlements.index');
Route::get('/geo/municipalities/{municipality}/settlements/{settlement}', [GeoSettlementController::class, 'show'])->name('geo.settlements.show');
Route::get('/geo/municipalities/{municipality}/settlements/{settlement}/places', [GeoPlaceController::class, 'forSettlement'])->name('geo.settlements.places');
Route::get('/geo/settlements/{settlement}', [GeoSettlementController::class, 'showById'])
    ->whereNumber('settlement')
    ->name('geo.settlements.show-id');
