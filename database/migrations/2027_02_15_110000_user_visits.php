<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Посещения человека (30.10.2026, владелец: «админам на странице пользователя — когда заходил, что делал, с какого
 * устройства»; управляющий парковки две недели не заходил, а видно было только «в сети 13.10»): строка — один заход с
 * одного устройства на один хост, продлевается отметкой «в сети» раз в минуту (`Users\Visit::mark`). Хранится 180 дней.
 * Стартовые строки — из живых сессий (последнее касание каждой), раньше журнала не было.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_visits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('surface', 12)->nullable();
            $t->timestamp('started_at');
            $t->timestamp('last_at');
            $t->unsignedInteger('hits')->default(1);
            $t->boolean('login')->default(false);
            $t->boolean('app')->default(false);
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 500)->nullable();
            $t->index(['user_id', 'last_at']);
            $t->index('last_at');
        });

        // Хост сессии неизвестен — без него; время — по часам приложения, как все `timestamp` базы.
        DB::statement('insert into user_visits (user_id, started_at, last_at, ip, user_agent)
            select s.user_id, to_timestamp(s.last_activity) at time zone ?, to_timestamp(s.last_activity) at time zone ?, s.ip_address, left(s.user_agent, 500)
            from sessions s join users u on u.id = s.user_id', [config('app.timezone'), config('app.timezone')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_visits');
    }
};
