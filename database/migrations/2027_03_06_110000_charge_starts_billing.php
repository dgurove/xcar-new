<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Строка хранения, с которой хранение ТС выставлено впервые (до неё «выставлено по» не было): аннулирование и сверка
 * после отправки возвращают ТС к «не выставлялось» и считают с приёма — даже если дату приёма потом сдвинули раньше
 * (05.11.2026: иначе дни до прежней даты терялись).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_charges', fn (Blueprint $t) => $t->boolean('starts_billing')->default(false));
    }

    public function down(): void
    {
        Schema::table('billing_charges', fn (Blueprint $t) => $t->dropColumn('starts_billing'));
    }
};
