<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Следы выплат менеджерам (18.11.2026, начальник: «вознаграждения менеджерам мы никогда не выплачиваем»): режим вознаграждения
 * сделки, счёт выплаты по гаражу и «Вознаграждение от поставщика». Код их не читает с выкладки 7aba9a27; снимаем следующей
 * выкладкой — во время той их ещё читали старые копии сайта.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', fn (Blueprint $t) => $t->dropColumn('commission_mode'));
        Schema::table('garage_cars', function (Blueprint $t) {
            $t->dropForeign(['payout_invoice_id']);
            $t->dropColumn('payout_invoice_id');
        });
        Schema::table('vendors', fn (Blueprint $t) => $t->dropColumn(['reward_kind', 'reward_value']));
    }
};
