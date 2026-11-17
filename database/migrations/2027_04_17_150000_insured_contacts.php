<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Все контакты владельца списком (17.11.2026, Т-Страхование по AUT-26-982161: «Нужина Елена Александровна +7(906)… / Нужин
 * Владислав Николаевич +7(967)…» — двое; `insured_name` / `insured_phone` / `insured_phone2` держат одного и второй номер):
 * `[{name, phone}]`. Первый контакт по-прежнему и в скалярных полях — их читают логистика и уведомления.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cars', fn (Blueprint $t) => $t->jsonb('insured_contacts')->nullable());
    }

    public function down(): void
    {
        Schema::table('cars', fn (Blueprint $t) => $t->dropColumn('insured_contacts'));
    }
};
