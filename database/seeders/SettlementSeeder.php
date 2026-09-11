<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Города из database/data/settlements.csv: код региона, тип, название. */
class SettlementSeeder extends Seeder
{
    private const FEDERAL = ['Москва', 'Санкт-Петербург', 'Севастополь'];

    public function run(): void
    {
        $rows = [];
        $now = now();
        foreach (file(database_path('data/settlements.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            [$region, $type, $name] = array_pad(explode(',', rtrim($line, ';'), 3), 3, null);
            $name = trim((string) $name, "; \t");
            if (! $name) {
                continue;
            }
            $rows[] = ['name' => $name, 'type' => trim($type) ?: null, 'region_code' => str_pad(trim($region), 2, '0', STR_PAD_LEFT), 'is_federal_city' => in_array($name, self::FEDERAL, true), 'created_at' => $now, 'updated_at' => $now];
        }
        foreach (self::FEDERAL as $i => $city) {
            if (! collect($rows)->contains('name', $city)) {
                $rows[] = ['name' => $city, 'type' => 'г.', 'region_code' => ['77', '78', '92'][$i], 'is_federal_city' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('settlements')->truncate();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('settlements')->insert($chunk);
        }
    }
}
