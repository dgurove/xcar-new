<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Обход архива Мигторга (`migtorg:archive`) — одна строка: докуда дошли вниз по номерам лотов (`cursor_id`), до какой
 * даты торгов обходим (`floor_date`), до какой даты торгов уже проверено (`reached_at` — для «Проверено до 12 сен» в
 * шторке), сколько подряд карточек старше границы (`misses`), и когда кончили (`done_at`). У лота — ещё город. Куски идут по расписанию и
 * переживают выкладку: следующий начинает с курсора.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migtorg_scan', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('cursor_id');
            $t->date('floor_date');
            $t->timestamp('reached_at')->nullable();
            $t->unsignedInteger('checked')->default(0);
            $t->unsignedInteger('found')->default(0);
            $t->unsignedSmallInteger('misses')->default(0);
            $t->timestamp('done_at')->nullable();
            $t->timestamps();
        });
        // Город лота — сверить глазами в шторке перед «Это она».
        Schema::table('migtorg_lots', fn (Blueprint $t) => $t->string('city', 80)->nullable());
    }

    public function down(): void
    {
        Schema::dropIfExists('migtorg_scan');
        Schema::table('migtorg_lots', fn (Blueprint $t) => $t->dropColumn('city'));
    }
};
