<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ветка письма покупателю (пропуск по QR) привязана к ТС, но это не переписка с вендором: письма вендору по ТС
 * (отчёты, запрос подтверждения покупателя) не должны отвечать в неё — иначе ушли бы покупателю.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_threads', fn (Blueprint $t) => $t->boolean('buyer')->default(false)->after('vehicle_id'));
    }

    public function down(): void
    {
        Schema::table('mail_threads', fn (Blueprint $t) => $t->dropColumn('buyer'));
    }
};
