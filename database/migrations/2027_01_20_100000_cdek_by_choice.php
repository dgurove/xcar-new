<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Документы СДЭКом» — выбор админа у задачи, а не печать человека (владелец 17.10.2026: «нужно выбирать, а не всем»; шлём,
 * когда машину в регионе вывозит логист — Совкомбанк, иногда Альфа). Печать (`users.has_seal`) больше ничего не решает.
 * Задачам, где СДЭК встал сам менеджеру или нашим, а трек ещё не ушёл, — снять, и «Отправьте документы» по ним из ленты.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('transports')->where('docs_by_cdek', true)->whereNull('docs_sent_at')
            ->whereIn('executor', ['manager', 'staff'])->whereNotIn('state', ['delivered', 'cancelled'])->pluck('id');
        DB::table('transports')->whereIn('id', $ids)->update(['docs_by_cdek' => false]);
        foreach ($ids as $id) {
            DB::table('notifications')->where('data->subject', '/transports/'.$id)
                ->where('data->title', 'like', 'Отправьте документы%')->delete();
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('has_seal'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->boolean('has_seal')->default(false));
    }
};
