<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Опоздавший покупатель платит только там, где так в договоре (ВСК): срок бесплатного хранения приходит
 * письмом на конкретную ТС, а не считается от даты продажи, поэтому правило «продажа плюс N дней»
 * (`buyer_storage_after_days`) уходит — реальности оно не соответствовало ни у одного вендора.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $t) {
            $t->boolean('buyer_pays_late')->default(false)->after('storage_payer');
            $t->dropColumn('buyer_storage_after_days');
        });
        Schema::table('park_vehicles', function (Blueprint $t) {
            // «Хранение за счёт вендора до» из письма страховой: дальше сутки идут покупателю по множителю.
            $t->date('buyer_free_until')->nullable()->after('sold_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $t) {
            $t->dropColumn('buyer_pays_late');
            $t->smallInteger('buyer_storage_after_days')->nullable();
        });
        Schema::table('park_vehicles', fn (Blueprint $t) => $t->dropColumn('buyer_free_until'));
    }
};
