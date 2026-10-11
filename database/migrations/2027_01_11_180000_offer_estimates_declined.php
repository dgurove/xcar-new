<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Отказаться от оценки» (11.10.2026, владелец): менеджер не берётся — например, заявленная высокая; желаемая стоимость
 * обязательна, причина — по желанию. Админ видит отказ строкой в окошке оценок, подробности — по нажатию.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offer_estimates', function (Blueprint $t) {
            $t->timestamp('declined_at')->nullable()->after('rejected_at');
            $t->unsignedBigInteger('wish_price')->nullable()->after('declined_at');
            $t->text('decline_reason')->nullable()->after('wish_price');
        });
    }

    public function down(): void
    {
        Schema::table('offer_estimates', fn (Blueprint $t) => $t->dropColumn(['declined_at', 'wish_price', 'decline_reason']));
    }
};
