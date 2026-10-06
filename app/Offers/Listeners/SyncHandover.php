<?php

namespace App\Offers\Listeners;

use App\Offers\CarPlace;
use App\Offers\Deal;
use App\Offers\Destination;
use App\Offers\Handover;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Actions\PlaceOnStage;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Events\StageEntered;
use App\Workflow\Outcome;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;

/**
 * Менеджер сделки сам забирает ТС у владельца (04.10.2026) — два маршрута про одно событие: продажа («Менеджер
 * забирает автомобиль» → «Автомобиль забрал») и вывоз («Вывоз и осмотр» → «Стоит у менеджера»). Нажали в одном —
 * другой догоняет сам, в каком бы порядке это ни случилось:
 * — продажа ушла с шага получения → вывоз встаёт на «Стоит у менеджера»;
 * — вывоз дошёл до «Стоит у менеджера» (админ нажал «Забрал» за него) → продажа жмёт свой «Автомобиль забрал»;
 * — продажа пришла на шаг получения, а ТС уже у него → шаг проходит сам.
 * С 05.10.2026 так же машина в гараж: везём её к менеджеру мы — «Автомобиль передан» продажи и «Стоит у менеджера»
 * вывоза догоняют друг друга, конец маршрута ставит машину на «Подготовку» (`CloseGarageDeal`).
 */
final class SyncHandover
{
    public function __construct(private PlaceOnStage $place, private TakeExit $take) {}

    public function subscribe(Dispatcher $events): array
    {
        return [StageEntered::class => 'entered'];
    }

    public function entered(StageEntered $e): void
    {
        $offer = $e->offer;
        $deal = $e->deal ?? $offer->deal()->first();
        // ТС едет к самому менеджеру сделки: забирает он сам или (гараж) везём ему мы.
        if (! $deal || $offer->pickupDestination() !== Destination::Keeper || $offer->keeper()?->id !== $deal->buyer_id) {
            return;
        }
        $deal->setRelation('offer', $offer);
        $by = $e->by ?? $this->system();

        if ($e->track === Track::Sale) {
            // Ушли с шага получения вперёд — ТС у менеджера.
            if ($e->exit && $e->from && $e->from->exits->contains(fn ($x) => self::handsOver($x))
                && ($service = $offer->position(Track::Service)) && ! $offer->pickedUp()
                && ($keeper = $service->stage->workflow->stageForPlace(CarPlace::Keeper))) {
                ($this->place)($offer, $keeper, $by);

                return;
            }
            // Пришли на шаг получения, а ТС уже у него.
            if ($offer->position(Track::Service)?->stage->car_place === CarPlace::Keeper) {
                $this->pressSale($deal, $by);
            }

            return;
        }
        if ($e->to->car_place === CarPlace::Keeper && $deal->isActive()) {
            $this->pressSale($deal, $by);
        }
    }

    private function pressSale(Deal $deal, User $by): void
    {
        $offer = $deal->offer->unsetRelation('positions');
        $stage = $offer->position(Track::Sale)?->stage;
        // Шаг его — «Автомобиль забрал»; отдавали мы (гараж, везём ему сами) — «Автомобиль передан».
        $exit = $stage?->exitsFor(Actor::Manager, $deal)->first(fn ($x) => Handover::picked($x))
            ?? $stage?->exitsFor(Actor::Staff, $deal)->first(fn ($x) => self::handsOver($x));
        if ($exit) {
            ($this->take)($offer, $exit, $exit->actor, $by);
        }
    }

    /** Исход шага передачи: менеджер забрал или мы передали. */
    private static function handsOver(Outcome $exit): bool
    {
        return ($exit->actor === Actor::Manager && Handover::picked($exit))
            || ($exit->actor === Actor::Staff && mb_strtolower(trim($exit->label)) === 'автомобиль передан');
    }

    private function system(): User
    {
        return User::withRole(Role::Admin)->orderBy('id')->firstOrFail();
    }
}
