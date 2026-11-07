<?php

declare(strict_types=1);

use App\Http\Controllers\GoogleController;
use Illuminate\Support\Facades\Route;

Route::get('/google/connect', [GoogleController::class, 'connect']);
Route::get('/google/callback', [GoogleController::class, 'callback']);
Route::get('/google/userinfo', [GoogleController::class, 'userinfo']);
Route::post('/google/disconnect', [GoogleController::class, 'disconnect']);
