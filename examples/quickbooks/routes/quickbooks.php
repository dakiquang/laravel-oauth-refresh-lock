<?php

declare(strict_types=1);

use App\Http\Controllers\QuickBooksController;
use Illuminate\Support\Facades\Route;

Route::get('/quickbooks/connect', [QuickBooksController::class, 'connect']);
Route::get('/quickbooks/callback', [QuickBooksController::class, 'callback']);
Route::get('/quickbooks/company', [QuickBooksController::class, 'company']);
Route::post('/quickbooks/disconnect', [QuickBooksController::class, 'disconnect']);
