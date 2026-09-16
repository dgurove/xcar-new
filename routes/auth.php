<?php

use App\Http\Auth\LoginController;
use App\Http\Auth\PasskeyController;
use App\Http\Auth\PasswordController;
use App\Http\Auth\InviteController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
    // Регистрации без приглашения нет: страница объясняет, где взять ссылку.
    Route::view('/register', 'auth.invite-only')->name('register');
    Route::get('/password', [PasswordController::class, 'forgot'])->name('password.request');
    Route::post('/password', [PasswordController::class, 'send'])->middleware('throttle:5,1');
    Route::get('/password/{token}', [PasswordController::class, 'reset'])->name('password.reset');
    Route::post('/password/new', [PasswordController::class, 'update']);

    Route::post('/passkey/login/options', [PasskeyController::class, 'loginOptions'])->middleware('throttle:20,1');
    Route::post('/passkey/login', [PasskeyController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    Route::post('/passkey/register/options', [PasskeyController::class, 'registerOptions'])->middleware('throttle:10,1');
    Route::post('/passkey/register', [PasskeyController::class, 'register'])->middleware('throttle:10,1');
    Route::delete('/passkey/{id}', [PasskeyController::class, 'destroy']);
});

// Ссылка на новый пароль от менеджера или админа: вошедшего контроллер сам выводит — ссылка должна сработать с любого телефона.
Route::get('/password/link/{token}', [PasswordController::class, 'link'])->middleware('throttle:30,1');
Route::post('/password/link/{token}', [PasswordController::class, 'setByLink'])->middleware('throttle:5,1');

// Пригласительная ссылка: и гостю, и вошедшему — контроллер сам решает, что показать.
Route::get('/i/{invite}', [InviteController::class, 'show'])->middleware('throttle:30,1');
Route::post('/i/{invite}', [InviteController::class, 'accept'])->middleware('throttle:5,1');
Route::post('/account/password', [PasswordController::class, 'change'])->middleware('auth');

// Ошибка ключа в браузере — гостем и вошедшим, чтобы её было видно в логе.
Route::post('/passkey/error', [PasskeyController::class, 'report'])->middleware('throttle:30,1');
