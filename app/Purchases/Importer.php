<?php

namespace App\Purchases;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Settlement;
use App\Cars\Vin\RememberVin;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/** Строки файла → машины закупки. Повторный импорт находит своё по ДЛ; правленое руками не перетирается. */
final class Importer
{
    public function __construct(private XlsxReader $reader) {}

    public function preview(Purchase $purchase, string $path): array
    {
        $rows = $this->reader->read($path);
        $known = $purchase->cars()->pluck('dl')->all();
        $stats = ['всего строк' => count($rows), 'новых' => 0, 'уже заведены' => 0, 'не прочитались' => 0, 'со ссылкой на сайт' => 0, 'со ссылкой на облако' => 0];
        foreach ($rows as $row) {
            if ($row['dl'] === null || $row['problems']) {
                $stats['не прочитались']++;

                continue;
            }
            $stats[in_array($row['dl'], $known, true) ? 'уже заведены' : 'новых']++;
            $stats['со ссылкой на сайт'] += $row['site_url'] ? 1 : 0;
            $stats['со ссылкой на облако'] += $row['cloud_url'] ? 1 : 0;
        }

        return ['rows' => $rows, 'stats' => $stats];
    }

    public function import(Purchase $purchase, string $path, User $by): array
    {
        $rows = $this->reader->read($path);
        $known = $purchase->cars()->get()->keyBy('dl');
        $created = $updated = $skipped = 0;
        foreach ($rows as $row) {
            if ($row['dl'] === null || $row['problems']) {
                $skipped++;

                continue;
            }
            DB::transaction(function () use ($purchase, $row, $known, &$created, &$updated) {
                if ($car = $known->get($row['dl'])) {
                    $this->update($car, $row);
                    $updated++;
                } else {
                    $known->put($row['dl'], $this->create($purchase, $row));
                    $created++;
                }
            });
        }
        $purchase->forceFill(['imported_at' => now(), 'imported_by' => $by->id])->save();

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    private function create(Purchase $purchase, array $row): Car
    {
        [$brandId, $modelId] = $this->catalog($row['brand'], $row['model']);

        return $purchase->cars()->create([
            'dl' => $row['dl'], 'ref' => Car::nextRef(), 'brand_id' => $brandId, 'model_id' => $modelId,
            'brand_raw' => $row['brand'], 'model_raw' => $row['model'], 'year' => $row['year'], 'address' => $row['address'],
            'settlement_id' => $this->settlement($row['address']), 'vehicle_type' => $row['vehicle_type'], 'kind' => $row['kind'],
            'stage' => $row['stage'], 'encumbrance' => $row['encumbrance'], 'fssp' => $this->fssp($row['encumbrance']),
            'price_revalued' => $row['price_revalued'], 'price_listing' => $row['price_listing'],
            'site_url' => $row['site_url'], 'cloud_url' => $row['cloud_url'],
            'specs_state' => $row['site_url'] ? ImportState::Pending : ImportState::Skipped,
            'photos_state' => $row['cloud_url'] ? ImportState::Pending : ImportState::Skipped,
        ]);
    }

    private function update(Car $car, array $row): void
    {
        $car->fill(['stage' => $row['stage'], 'encumbrance' => $row['encumbrance'], 'price_revalued' => $row['price_revalued'], 'price_listing' => $row['price_listing'], 'kind' => $row['kind'], 'vehicle_type' => $row['vehicle_type'] ?? $car->vehicle_type]);
        $car->fssp ??= $this->fssp($row['encumbrance']);
        if ($row['site_url'] && $row['site_url'] !== $car->site_url) {
            $car->fill(['site_url' => $row['site_url'], 'specs_state' => ImportState::Pending, 'specs_error' => null]);
        }
        if ($row['cloud_url'] && $row['cloud_url'] !== $car->cloud_url) {
            $car->fill(['cloud_url' => $row['cloud_url'], 'photos_state' => ImportState::Pending, 'photos_error' => null]);
        }
        foreach (['year', 'address'] as $f) {
            $car->{$f} ??= $row[$f];
        }
        if (! $car->settlement_id && ! $car->isLocked('settlement_id')) {
            $car->settlement_id = $this->settlement($car->address);
        }
        if (in_array($car->specs_state, [ImportState::Skipped, ImportState::Gone, ImportState::Failed], true)) {
            foreach (['brand_raw' => $row['brand'], 'model_raw' => $row['model']] as $f => $v) {
                if ($v !== null && ! $car->isLocked($f)) {
                    $car->{$f} = $v;
                }
            }
            [$brandId, $modelId] = $this->catalog($row['brand'], $row['model']);
            if ($brandId && ! $car->isLocked('brand_id')) {
                $car->brand_id = $brandId;
                $car->model_id = $modelId;
            }
        }
        $car->save();
        (new RememberVin)($car);
    }

    private function catalog(?string $brand, ?string $model): array
    {
        if (! $brand) {
            return [null, null];
        }
        $found = Brand::whereRaw('lower(name) = ?', [mb_strtolower(trim($brand))])->orWhereRaw('lower(name_ru) = ?', [mb_strtolower(trim($brand))])->first();
        if (! $found) {
            return [null, null];
        }
        $carModel = $model ? CarModel::where('brand_id', $found->id)->whereRaw('lower(name) = ?', [mb_strtolower(trim($model))])->first() : null;

        return [$found->id, $carModel?->id];
    }

    private function settlement(?string $address): ?int
    {
        if (! $address) {
            return null;
        }
        foreach (preg_split('/[,;]+/u', $address) as $part) {
            $part = trim(preg_replace('/^(г\.?|город|пос\.?|с\.?|д\.?)\s+/iu', '', trim($part)));
            if (mb_strlen($part) < 3) {
                continue;
            }
            if ($s = Settlement::whereRaw('lower(name) = ?', [mb_strtolower($part)])->first()) {
                return $s->id;
            }
        }

        return null;
    }

    private function fssp(?string $encumbrance): ?bool
    {
        if (! $encumbrance) {
            return null;
        }
        $v = mb_strtolower($encumbrance);

        return str_contains($v, 'нет') ? false : (str_contains($v, 'фссп') ? true : null);
    }
}
