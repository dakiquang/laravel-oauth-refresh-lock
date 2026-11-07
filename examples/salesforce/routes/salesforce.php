<?php

declare(strict_types=1);

use App\Http\Controllers\SalesforceController;
use Illuminate\Support\Facades\Route;

Route::get('/salesforce/connect', [SalesforceController::class, 'connect']);
Route::get('/salesforce/callback', [SalesforceController::class, 'callback']);
Route::get('/salesforce/organisation', [SalesforceController::class, 'organisation']);
Route::post('/salesforce/disconnect', [SalesforceController::class, 'disconnect']);
