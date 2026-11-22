<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Личка (22.11.2026, менеджеры: «написать менеджеру не по тачке, свободная переписка»): чат без машины с двумя людьми —
 * `offer_id` пуст, `manager_id` задан (так же, как переписка покупателя со своим менеджером). У пары — одна личка в любую
 * сторону: индекс по паре без порядка. Дубли руками не сливаются в миграции — она останавливается со списком.
 */
return new class extends Migration
{
    public function up(): void
    {
        $dupes = DB::select('select least(user_id, manager_id) a, greatest(user_id, manager_id) b, count(*) n from chats
            where offer_id is null and manager_id is not null group by 1, 2 having count(*) > 1');
        if ($dupes) {
            throw new RuntimeException('Дубли личек: '.collect($dupes)->map(fn ($d) => "{$d->a}↔{$d->b} ×{$d->n}")->implode(', '));
        }
        DB::statement('create unique index chats_direct_pair on chats (least(user_id, manager_id), greatest(user_id, manager_id))
            where offer_id is null and manager_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists chats_direct_pair');
    }
};
