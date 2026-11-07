<?php

namespace App\Cars\Console;

use App\Cars\Car;
use App\Cars\Sources\Collect;
use App\Mail\Scan\AutoScan;
use App\Offers\OfferState;
use App\Park\VehicleState;
use Illuminate\Console\Command;

/**
 * Источники живых машин — в варианты полей (`Cars\Sources\Collect`, 07.11.2026): черновики, галерея, продажа, сделки,
 * гараж, ТС на парковке и в пути. Без `--fill` пустые поля не вписываются — только собрать и посчитать спорные; `--read` —
 * ещё поставить в чтение непрочитанные документы (сканы из прошлого никто не читал).
 */
class CollectValuesCommand extends Command
{
    protected $signature = 'cars:collect-values {--fill : вписать пустые поля, где источники согласны} {--read : поставить в чтение непрочитанные документы} {--car=* : только эти машины}';

    protected $description = 'Варианты полей машин из Мигторга, писем и документов';

    public function handle(Collect $collect): int
    {
        $ids = array_map('intval', (array) $this->option('car'));
        // «Или» — в скобках: иначе условие страниц `lazyById` (id > …) встаёт только ко второй половине, и обход крутится по кругу.
        $cars = Car::query()->when($ids, fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->where(fn ($w) => $w
            ->whereHas('offer', fn ($o) => $o->whereIn('state', [OfferState::Draft, OfferState::Gallery, OfferState::Open, OfferState::Sold, OfferState::Garage]))
            ->orWhereHas('vehicle', fn ($v) => $v->whereIn('state', [VehicleState::Expected, VehicleState::Stored]))));
        $n = $disputed = 0;
        foreach ($cars->with(['offer', 'vehicle'])->lazyById(100) as $car) {
            $collect($car, fill: (bool) $this->option('fill'));
            if ($this->option('read')) {
                foreach (array_filter([$car->offer, $car->vehicle]) as $lane) {
                    AutoScan::readDocs($lane);
                }
            }
            $n++;
            $disputed += $car->refresh()->value_conflicts > 0 ? 1 : 0;
        }
        $this->info("Машин: {$n}, со спорными полями: {$disputed}");

        return self::SUCCESS;
    }
}
