<?php

namespace App\Garage\Console;

use App\Garage\Actions\EnsurePickup;
use App\Garage\Actions\GiveToGarage;
use App\Garage\Car;
use App\Garage\GaragePayer;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разовая починка прода под три дорожки (06.10.2026, решения владельца):
 * — черновики Совкомбанка, которые таймер срока страховой увёл к «Покупателю от поставщика», — назад в «Черновик»;
 * — машины, отданные в гараж руками без сделки, — гаражной сделкой (1156, 1165: «мы платим Совкому по договору
 *   комиссии, менеджер платит нам на ПРАЙМ» — платит менеджер; 1211 — забираем себе, платим мы), маршрут продажи — с
 *   начала сделки, вывоз — к менеджеру (везёт он сам) или к нам;
 * — гаражные машины без вывоза — вывоз (1299 везёт сама Екатерина).
 * Без `--apply` — только что будет сделано. Повторный запуск ничего не трогает: всё уже на месте.
 */
class GarageReformFixCommand extends Command
{
    protected $signature = 'garage:reform-fix {--apply : сделать, а не только показать}';

    protected $description = 'Три дорожки: вернуть сгоревшие черновики Совкомбанка, ручной гараж — гаражной сделкой, вывоз у каждой гаражной машины';

    /** Сгоревшие по сроку страховой черновики (журнал прода 04–06.10.2026). */
    private const BURNED = ['1153', '1158', '1160', '1166'];

    /** Отданные руками до 06.10.2026: номер → [кто платит поставщику, где машина]. */
    private const MANUAL = [
        '1156' => [GaragePayer::Manager, 'arrived'],
        '1165' => [GaragePayer::Manager, 'owner'],
        '1211' => [GaragePayer::Us, 'owner'],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $by = User::withRole(Role::Admin)->orderBy('id')->first();
        if (! $by) {
            $this->error('Нет ни одного админа');

            return self::FAILURE;
        }
        $insurer = Stage::where('name', 'Запрос покупателя у поставщика')->pluck('id');
        if (Outcome::whereIn('to_stage_id', $insurer)->where('actor', Actor::Timer->value)->exists() || Stage::where('name', 'Выдача автомобиля — в гараж')->exists()) {
            $this->error('Маршруты ещё старые: сначала миграция three_lanes_routes');

            return self::FAILURE;
        }

        foreach (self::BURNED as $number) {
            $offer = OfferNumber::find($number);
            $stage = $offer?->position(Track::Sale)?->stage;
            if (! $offer || $offer->state !== OfferState::Draft || ! $stage || ! $stage->block || $stage->block->name !== 'Покупатель от поставщика') {
                $this->line("{$number}: пропуск (".($offer ? $offer->state->value.', '.($stage?->name ?? 'без маршрута') : 'нет такого').')');

                continue;
            }
            $draft = Stage::where('workflow_id', $stage->workflow_id)->where('name', 'Черновик')->first();
            $this->line("{$number}: «{$stage->name}» → «Черновик»");
            if ($apply && $draft) {
                DB::transaction(function () use ($offer, $draft, $by) {
                    app(PlaceOnStage::class)($offer, $draft, $by, back: true);
                    $offer->log(OfferEventType::Note, $by, ['text' => 'Вернули в черновик: срок страховой больше не уводит к покупателю поставщика']);
                });
            }
        }

        foreach (self::MANUAL as $number => [$payer, $where]) {
            $offer = OfferNumber::find((string) $number);
            $car = $offer ? Car::where('offer_id', $offer->id)->with('manager')->first() : null;
            if (! $offer || ! $car || $car->deal_id) {
                $this->line("{$number}: пропуск (".(! $car ? 'не в гараже' : 'сделка уже есть').')');

                continue;
            }
            $this->line("{$number}: гаражная сделка, ".($car->manager?->name ?? 'взяли под себя').', платит '.mb_strtolower($payer->label()).", машина: {$where}");
            if ($apply) {
                app(GiveToGarage::class)($offer, $car->manager, $payer, $by, $where, $car->manager ?? null);
                $offer = $offer->fresh();
                $this->line('    продажа: '.($offer->stage(Track::Sale)?->name ?? '—').', вывоз: '.($offer->stage(Track::Service)?->name ?? '—').', гараж: '.$car->fresh()->state->label());
            }
        }

        // Любая гаражная машина в работе без вывоза — вывоз к её менеджеру (везёт он сам) или к нам.
        foreach (Car::with('offer', 'manager')->whereNotIn('state', ['sold', 'settled'])->get() as $car) {
            $offer = $car->offer;
            if (! $offer || $offer->position(Track::Service)) {
                continue;
            }
            $this->line("{$offer->number}: вывоз ".($car->manager ? 'к '.$car->manager->name.', везёт сам' : 'к нам'));
            if ($apply) {
                app(EnsurePickup::class)($offer, $by, $car->manager);
            }
        }

        // Остальное — только посмотреть: гаражные сделки, их этап продажи и где машина.
        foreach (Car::with('offer', 'deal')->whereNotNull('deal_id')->whereNotIn('state', ['settled'])->get() as $car) {
            $o = $car->offer->fresh();
            $this->line("  {$o->number}: {$car->state->label()}, продажа «".($o->stage(Track::Sale)?->name ?? '—').'», вывоз «'.($o->stage(Track::Service)?->name ?? '—').'»');
        }
        $this->info($apply ? 'Готово' : 'Только показал: --apply, чтобы сделать');

        return self::SUCCESS;
    }
}
