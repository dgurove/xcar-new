<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Демонстрационный кабинет (для проверяющих платёжного сервиса): пользователь, его контрагент,
 * предложения, сделки и счета с флагом `is_demo`. Сотрудники, фоновые задачи и выписка их не видят
 * (глобальная область `Support\Demo`), демо-пользователь видит своё и настоящий каталог.
 */
return new class extends Migration
{
    private const TABLES = ['users', 'offers', 'deals', 'billing_invoices', 'billing_parties'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->boolean('is_demo')->default(false)->index());
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('is_demo'));
        }
    }
};
