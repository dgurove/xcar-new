<?php

use App\Http\Cabinet\ProfileController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'site.home')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/lk', [ProfileController::class, 'show'])->name('cabinet');
    Route::put('/lk', [ProfileController::class, 'update']);
});

// Заглушки разделов до их этапов — чтобы навигация была проверяема с телефона.
foreach ([
    '/galereya' => 'Галерея', '/zakupki' => 'Закупки', '/lk/izbrannoe' => 'Избранное', '/lk/uvedomleniya' => 'Уведомления',
    '/lk/sdelki' => 'Сделки', '/admin/offers' => 'Офферы', '/admin/razgovory' => 'Разговоры', '/admin/sdelki' => 'Сделки',
    '/admin/zakupki' => 'Закупки', '/admin/eshchyo' => 'Ещё',
] as $path => $title) {
    Route::view($path, 'site.placeholder', ['title' => $title]);
}
