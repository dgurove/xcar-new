<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** «Стоит у нас» вывоза: парковки в вывозе нет (08.10.2026) — текст блока без «не на парковке» и без точки. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('workflow_blocks')->where('name', 'Стоит у нас')->where('text', 'like', 'Автомобиль стоит у нас, не на парковке%')
            ->update(['text' => 'Автомобиль стоит у нас']);
    }

    public function down(): void {}
};
