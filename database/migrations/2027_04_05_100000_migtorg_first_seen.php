<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Когда лот впервые появился на Мигторге (15.11.2026, владелец: «появление на Мигторге произошло ПОСЛЕ заявки на приём, а значит
 * та оценка уже не актуальна»): оценочная с датой раньше появления лота неактуальна (`Offers\Valuations::lotAt`). Мигторг даты
 * начала не отдаёт — у нас только «видели последний раз» (`seen_at`). Прошлому: самое раннее из первого события машины с этим
 * лотом в «Истории», черновика с него (`drafted_at`) и `seen_at` — у него и у всех лотов с бо́льшим номером (номера идут по
 * порядку: появился не позже их). Дальше ставит синхронизация при первой встрече (`MigtorgSync`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migtorg_lots', fn (Blueprint $t) => $t->timestamp('first_seen_at')->nullable());
        // Верхняя оценка у каждого лота — первое событие с ним, черновик с него, «видели последний раз»; лоты с бо́льшим номером
        // появились позже, поэтому дата лота — самая ранняя из его и всех более новых (поздно связанный лот иначе выглядел бы
        // новым и делал неактуальной оценочную, данную, когда он уже был).
        DB::statement(<<<'SQL'
            update migtorg_lots l set first_seen_at = x.at
            from (select id, min(est) over (order by id desc rows between unbounded preceding and current row) at
                  from (select l.id, least(e.at, l.drafted_at, l.seen_at) est from migtorg_lots l
                        left join (select (payload->>'lot')::bigint lot, min(created_at) at from car_events
                                   where payload->>'lot' ~ '^\d+$' group by 1) e on e.lot = l.id) t) x
            where x.id = l.id
            SQL);
    }

    public function down(): void
    {
        Schema::table('migtorg_lots', fn (Blueprint $t) => $t->dropColumn('first_seen_at'));
    }
};
