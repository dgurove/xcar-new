<?php

use App\Cars\Duplicates;
use App\Cars\Vin\Vin;
use App\Mail\Extraction\Code;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Одна машина — одна запись (05.10.2026, владелец: «VIN или номер убытка не могут существовать в двух разных
 * предложениях»). Ключи номера пересчитываются (кириллическая «О» стала латиницей), VIN — одной записью
 * (`Cars\Vin\Vin`), двойники объединяются (`Cars\Duplicates`), и дальше их держат уникальные индексы: номер убытка —
 * всегда, VIN — среди живых.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['offers' => 'claim_ref', 'park_vehicles' => 'ref', 'migtorg_lots' => 'claim_ref'] as $table => $column) {
            foreach (DB::table($table)->whereNotNull($column)->get(['id', $column, $column.'_key']) as $row) {
                $key = Code::key($row->{$column});
                if ($key !== $row->{$column.'_key'}) {
                    DB::table($table)->where('id', $row->id)->update([$column.'_key' => $key]);
                }
            }
        }
        foreach (['offers', 'park_vehicles'] as $table) {
            foreach (DB::table($table)->whereNotNull('vin')->get(['id', 'vin']) as $row) {
                $vin = Vin::normalize($row->vin);
                if ($vin !== $row->vin && strlen((string) $vin) <= 17) {
                    DB::table($table)->where('id', $row->id)->update(['vin' => $vin]);
                }
            }
        }

        Duplicates::mergeAll(fn ($line) => Log::info('dups:merge '.$line));

        DB::statement('create unique index offers_claim_ref_key_unique on offers (claim_ref_key) where claim_ref_key is not null and is_demo = false');
        DB::statement("create unique index offers_vin_live_unique on offers (vin) where length(vin) = 17 and is_demo = false and state not in ('delivered', 'cancelled', 'archived')");
        DB::statement('create unique index park_vehicles_ref_key_unique on park_vehicles (ref_key) where ref_key is not null');
        DB::statement("create unique index park_vehicles_vin_live_unique on park_vehicles (vin) where length(vin) = 17 and state not in ('released', 'cancelled')");
    }

    public function down(): void
    {
        foreach (['offers_claim_ref_key_unique', 'offers_vin_live_unique', 'park_vehicles_ref_key_unique', 'park_vehicles_vin_live_unique'] as $index) {
            DB::statement("drop index if exists {$index}");
        }
    }
};
