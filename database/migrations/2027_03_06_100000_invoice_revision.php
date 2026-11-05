<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Редакция документа парковки (05.11.2026, владелец: отправленный документ, чьи данные потом исправили, уходит
 * исправленным с тем же номером и «ред. 1», как в архиве «СТОЯНКА»). Прежний — аннулирован, номер повторяется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->unsignedSmallInteger('revision')->default(0)->after('code');
            $t->dropUnique(['seller', 'code']);
            $t->unique(['seller', 'code', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $t) {
            $t->dropUnique(['seller', 'code', 'revision']);
            $t->unique(['seller', 'code']);
            $t->dropColumn('revision');
        });
    }
};
