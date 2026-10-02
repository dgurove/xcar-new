<?php

namespace App\Cars;

use Illuminate\Support\Facades\DB;

/**
 * Справочник населённых пунктов из ОКТМО (database/data/settlements.csv.gz, собирает scripts/oktmo_settlements.py)
 * и регионов (regions.csv). Прежний справочник был списком муниципалитетов: центры городских округов (Серпухов,
 * Коломна, Люберцы) в нём пропали, сёл и деревень не было вовсе, названия из двух слов склеены «;». Прежние города
 * остаются теми же строками — на них ссылаются предложения, парковки и закупки; что не нашлось (поселения,
 * «территории»), уходит, а ссылки на него переезжают на место с тем же именем. Повторный запуск ничего не делает.
 */
final class SettlementImport
{
    private const OLD_CITY = ['г.', 'пгт'];

    public function __invoke(): void
    {
        if (DB::table('settlements')->whereNotNull('region_id')->exists()) {
            return;
        }
        $regions = $this->regions();

        // Прежние города по имени и номеру региона — их строки остаются.
        $old = [];
        $fix = [];
        foreach (DB::table('settlements')->get(['id', 'name', 'type', 'region_code']) as $row) {
            $name = trim((string) preg_replace('/\s+/u', ' ', str_replace(';', ' ', $row->name)));
            $fix[$row->id] = $name;
            if (in_array($row->type, self::OLD_CITY, true)) {
                $old[$this->key($name, $row->region_code)] ??= $row->id;
            }
        }

        $now = now();
        $insert = [];
        $kept = [];
        $file = gzopen(database_path('data/settlements.csv.gz'), 'r');
        gzgets($file);
        while (($line = gzgets($file)) !== false) {
            [$oktmo, $regionKey, $type, $name, $district, $rank] = str_getcsv(rtrim($line, "\n"), ';', '"', '');
            $region = $regions[$regionKey];
            $row = [
                'name' => $name, 'type' => $type, 'region_id' => $region['id'], 'region_code' => $region['plate'],
                'district' => $district !== '' && $district !== $name ? mb_substr($district, 0, 160) : null,
                'oktmo' => $oktmo, 'rank' => (int) $rank,
                'is_federal_city' => in_array($regionKey, ['45', '40', '67'], true) && $district === '' && (int) $rank === 1,
            ];
            $id = in_array($type, ['г', 'пгт'], true) ? ($old[$this->key($name, $region['plate'])] ?? null) : null;
            if ($id !== null && ! isset($kept[$id])) {
                $kept[$id] = true;
                DB::table('settlements')->where('id', $id)->update($row + ['updated_at' => $now]);

                continue;
            }
            $insert[] = $row + ['created_at' => $now, 'updated_at' => $now];
            if (count($insert) === 1000) {
                DB::table('settlements')->insert($insert);
                $insert = [];
            }
        }
        gzclose($file);
        if ($insert) {
            DB::table('settlements')->insert($insert);
        }

        // Не нашлось — ссылки переезжают на место с тем же именем (сначала город), сама строка уходит.
        foreach (array_diff_key($fix, $kept) as $id => $name) {
            $to = DB::table('settlements')->whereNotNull('region_id')->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('rank')->value('id');
            foreach (['offers', 'park_yards', 'purchase_cars'] as $table) {
                DB::table($table)->where('settlement_id', $id)->update(['settlement_id' => $to]);
            }
            DB::table('settlements')->where('id', $id)->delete();
        }
    }

    /** @return array<string, array{id: int, plate: string}> ключ ОКТМО → регион */
    private function regions(): array
    {
        $lines = file(database_path('data/regions.csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        array_shift($lines);
        foreach ($lines as $line) {
            [$oktmo, $plate, $name, $short, $match] = explode(';', $line, 5);
            DB::table('regions')->updateOrInsert(['oktmo' => $oktmo], ['plate' => $plate, 'name' => $name, 'short' => $short, 'match' => $match]);
        }

        return DB::table('regions')->get(['id', 'oktmo', 'plate'])->mapWithKeys(fn ($r) => [$r->oktmo => ['id' => $r->id, 'plate' => $r->plate]])->all();
    }

    private function key(string $name, ?string $region): string
    {
        return str_replace('ё', 'е', mb_strtolower($name)).'|'.str_pad((string) $region, 2, '0', STR_PAD_LEFT);
    }
}
