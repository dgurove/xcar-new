<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 21.10.2026, владелец: «менеджеры не видят, когда приходят новые машины, проёбываемся по срокам». За 3 часа до конца
 * приёма — одно напоминание о машинах, которые человек не открывал (`RemindUnreadClosing`); отметка — чтобы не повторять.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_viewers', function (Blueprint $t) {
            $t->timestamp('closing_reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offer_viewers', function (Blueprint $t) {
            $t->dropColumn('closing_reminded_at');
        });
    }
};
