<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::get('/', function () {
    return view('welcome');
});

// ── Google OAuth Routes (web, bukan api) ──────────────
// Bertindak sebagai fail-safe jika redirect URI Google mengarah ke path tanpa /api/v1
Route::get('/auth/google',          [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
