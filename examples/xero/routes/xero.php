<?php

declare(strict_types=1);

use App\Http\Controllers\XeroController;
use Illuminate\Support\Facades\Route;

Route::get('/xero/connect', [XeroController::class, 'connect']);
Route::get('/xero/callback', [XeroController::class, 'callback']);
Route::get('/xero/organisation', [XeroController::class, 'organisation']);
Route::post('/xero/disconnect', [XeroController::class, 'disconnect']);
