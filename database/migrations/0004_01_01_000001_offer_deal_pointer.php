<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Колонки сделки-состояния (16.11.2026, `2027_04_11_100000_deal_state_into_offer`) — сразу за созданием таблиц: старые
 * миграции с данными ходят через модели, а модели уже читают `offers.deal_id`, `deals.parallel` и `deals.cancelled_at`
 * (переписывать прошлые миграции на голый SQL ради пустой базы тестов — риск без пользы: на проде они уже выполнены). На
 * проде миграция выполняется первой из новых и только добавляет колонки; данные переносит основная.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('offers', 'deal_id')) {
            Schema::table('offers', fn (Blueprint $t) => $t->foreignId('deal_id')->nullable()->constrained('deals')->nullOnDelete());
        }
        if (! Schema::hasColumn('deals', 'parallel')) {
            Schema::table('deals', fn (Blueprint $t) => $t->boolean('parallel')->default(false));
        }
        if (! Schema::hasColumn('deals', 'cancelled_at')) {
            Schema::table('deals', fn (Blueprint $t) => $t->timestamp('cancelled_at')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('offers', 'deal_id')) {
            Schema::table('offers', fn (Blueprint $t) => $t->dropConstrainedForeignId('deal_id'));
        }
        foreach (['parallel', 'cancelled_at'] as $column) {
            if (Schema::hasColumn('deals', $column)) {
                Schema::table('deals', fn (Blueprint $t) => $t->dropColumn($column));
            }
        }
    }
};
