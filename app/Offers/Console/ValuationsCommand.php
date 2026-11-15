<?php

namespace App\Offers\Console;

use App\Cars\Car;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Pricing;
use App\Offers\Valuations;
use App\Park\VehicleState;
use App\Support\Ago;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Сверка оценочных по датам (15.11.2026, `Offers\Valuations`): прошлому — `value_at` по «Истории», и где заявка поставщика во
 * вложении письма свежее нынешней оценочной (0790/046/10760/26: из текста 18.10 — 5 862 856, заявка 23.10 — 3 033 000), —
 * поставить её. Текст документов — только прочитанный (кеш, текстовый слой), OCR не запускается.
 * `--dry` — только список, ничего не пишет.
 */
final class ValuationsCommand extends Command
{
    protected $signature = 'offers:valuations {--dry}';

    protected $description = 'Оценочные по дате источника: проставить даты и поставить свежие заявки поставщика';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        if (! $dry) {
            $this->dates();
        }
        $vendors = DB::table('vendors')->get(['id'])->pluck('id')->filter(fn ($id) => Pricing::byValue($id))->all();
        $cars = Car::query()->where(fn ($q) => $q
            ->whereHas('offer', fn ($o) => $o->whereIn('state', [OfferState::Draft, OfferState::Gallery, OfferState::Open])->whereIn('vendor_id', $vendors))
            ->orWhereHas('vehicle', fn ($v) => $v->whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled])))
            ->orderBy('id')->get();
        $rows = [];
        $bar = $this->output->createProgressBar($cars->count());
        foreach ($cars as $car) {
            $bar->advance();
            $offer = $car->offer;
            $sale = $offer ? Valuations::proposal($offer) : null;
            $park = Valuations::parkProposal($car);
            if (! $sale && ! $park) {
                continue;
            }
            $name = $offer ? ($offer->claim_ref ?: '№ '.$offer->number) : ($car->claim_ref ?: 'ТС '.$car->vehicle?->id);
            $sale && $rows[] = [$name, 'продажа '.$offer->state->label().($offer->bids()->whereIn('state', [\App\Offers\BidState::Active, \App\Offers\BidState::Accepted])->exists() ? ', есть подтверждения' : ''),
                self::was($sale['was'], $sale['was_at']), Money::rub($sale['value']).' '.$sale['title'],
                'закупочная '.($offer->floor_price ? Money::rub((int) $offer->floor_price) : 'нет').' → '.Money::rub(Pricing::floorFrom($sale['value'], Pricing::rulesFor($offer)))];
            $park && $rows[] = [$name, 'парковка', self::was($park['was'], $car->value_at), Money::rub($park['value']).' '.$park['title'], ''];
            if (! $dry) {
                Valuations::fromDocuments($car);
            }
        }
        $bar->finish();
        $this->newLine();
        $this->table(['Машина', 'Где', 'Было', 'Свежее', 'Цены'], $rows);
        $this->line(($dry ? 'Встанет: ' : 'Поставлено: ').count($rows));

        return self::SUCCESS;
    }

    private static function was(?int $value, ?Carbon $at): string
    {
        return $value === null ? 'нет' : Money::rub($value).($at ? ' от '.Ago::day($at) : '');
    }

    /** Даты нынешних оценочных: продаже — последняя правка оценочной в «Истории», ТС парковки — правка на парковке или заявка. */
    private function dates(): void
    {
        $n = 0;
        Offer::query()->whereNotNull('value')->whereNull('value_at')->orderBy('id')->chunkById(500, function ($offers) use (&$n) {
            foreach ($offers as $offer) {
                $offer->forceFill(['value_at' => Valuations::at($offer)])->saveQuietly();
                $n++;
            }
        });
        $cars = DB::update(<<<'SQL'
            update cars c set value_at = coalesce(
                (select max(e.created_at) from park_vehicle_events e join park_vehicles v on v.id = e.vehicle_id
                  where v.car_id = c.id and jsonb_exists(e.payload->'changes', 'value')),
                (select min(r.letter_at) from park_requests r join park_vehicles v on v.id = r.vehicle_id where v.car_id = c.id),
                (select max(e.created_at) from car_events e where e.car_id = c.id and jsonb_exists(e.payload->'changes', 'value')),
                c.created_at)
            where c.value is not null and c.value_at is null
            SQL);
        $this->line("Даты оценочных: продаж {$n}, машин {$cars}");
    }
}
