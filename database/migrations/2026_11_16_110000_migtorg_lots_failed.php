<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Лот, фото которого не взялись трижды (карточка снята, вход отвергнут), сутки не берётся сам — иначе синхронизация
 * ставила бы задачу раз в полчаса без конца; руками «С Мигторга» можно. Время правки лота не читал никто — снято.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migtorg_lots', function (Blueprint $t) {
            $t->timestamp('failed_at')->nullable();
            $t->dropColumn('lot_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('migtorg_lots', function (Blueprint $t) {
            $t->dropColumn('failed_at');
            $t->timestamp('lot_updated_at')->nullable();
        });
    }
};
