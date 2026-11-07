<?php

declare(strict_types=1);

use App\Http\Controllers\MicrosoftController;
use Illuminate\Support\Facades\Route;

Route::get('/microsoft/connect', [MicrosoftController::class, 'connect']);
Route::get('/microsoft/callback', [MicrosoftController::class, 'callback']);
Route::get('/microsoft/me', [MicrosoftController::class, 'me']);
Route::post('/microsoft/disconnect', [MicrosoftController::class, 'disconnect']);
