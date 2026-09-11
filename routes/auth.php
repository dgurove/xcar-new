<?php

use App\Http\Auth\LoginController;
use App\Http\Auth\PasskeyController;
use App\Http\Auth\PasswordController;
use App\Http\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/vhod', [LoginController::class, 'show'])->name('login');
    Route::post('/vhod', [LoginController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/registraciya', [RegisterController::class, 'show'])->name('register');
    Route::post('/registraciya', [RegisterController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/parol', [PasswordController::class, 'forgot'])->name('password.request');
    Route::post('/parol', [PasswordController::class, 'send'])->middleware('throttle:5,1');
    Route::get('/parol/{token}', [PasswordController::class, 'reset'])->name('password.reset');
    Route::post('/parol/novyj', [PasswordController::class, 'update']);

    Route::post('/passkey/login/options', [PasskeyController::class, 'loginOptions']);
    Route::post('/passkey/login', [PasskeyController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/vyhod', [LoginController::class, 'logout'])->name('logout');
    Route::post('/passkey/register/options', [PasskeyController::class, 'registerOptions']);
    Route::post('/passkey/register', [PasskeyController::class, 'register']);
    Route::delete('/passkey/{id}', [PasskeyController::class, 'destroy']);
});
