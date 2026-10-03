<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Города менеджера (03.10.2026, решение владельца: «очень важное поле»): при регистрации менеджер указывает, в каких
 * городах он работает, их может быть несколько. Уже зарегистрированным ставим Москву — других городов у них пока нет,
 * менеджеры из остальных городов только придут.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_settlements', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('settlement_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->primary(['user_id', 'settlement_id']);
        });

        $moscow = DB::table('settlements')->where('name', 'Москва')->orderByDesc('is_federal_city')->value('id')
            ?? DB::table('settlements')->insertGetId(['name' => 'Москва', 'type' => 'г', 'region_code' => '77', 'is_federal_city' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::statement('insert into user_settlements (user_id, settlement_id, created_at, updated_at) select id, ?, now(), now() from users where role = ?', [$moscow, 'manager']);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settlements');
    }
};
