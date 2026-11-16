<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Номер договора — «ГГГГММДД-NN» по дню «Отправить менеджеру», одна очередь на ПРАЙМ и Р.О.С. (16.11.2026, владелец: «не
 * „ПР-4/2026“, всегда было годмесяцдень-01, -02…»). `contract_days` — счётчик дня (`Offers\ContractNumber`), `code` уникален.
 * Прежние «ПР-N/2026» перенумерованы по дню отправки (владелец: «перенумеровать все»). `number`, `year`, `issuer` остаются на
 * одну выкладку — их читают старые контейнеры, пока она идёт.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_contracts', function (Blueprint $table) {
            $table->string('code', 24)->nullable()->unique();
            $table->date('numbered_on')->nullable();
        });
        Schema::create('contract_days', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('last');
        });
        $rows = DB::table('deal_contracts')->whereNotNull('number')
            ->orderByRaw('coalesce(shown_at, ready_at, created_at)')->orderBy('id')->get(['id', 'shown_at', 'ready_at', 'created_at']);
        $last = [];
        foreach ($rows as $row) {
            // Время в базе — по часам приложения (Москва, `app.timezone`).
            $day = Carbon::parse($row->shown_at ?? $row->ready_at ?? $row->created_at)->setTimezone('Europe/Moscow');
            $key = $day->toDateString();
            $n = $last[$key] = ($last[$key] ?? 0) + 1;
            DB::table('deal_contracts')->where('id', $row->id)->update(['code' => $day->format('Ymd').'-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'numbered_on' => $key]);
        }
        foreach ($last as $day => $n) {
            DB::table('contract_days')->insert(['day' => $day, 'last' => $n]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_days');
        Schema::table('deal_contracts', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'numbered_on']);
        });
    }
};
