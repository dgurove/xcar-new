<?php

namespace App\Offers\Listeners;

use App\Logistics\Events\TransportMoved;
use App\Logistics\Point;
use App\Offers\CarPlace;
use App\Offers\Deal;
use App\Offers\Handover;
use App\Offers\Offer;
use App\Users\Role;
use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Events\StageEntered;
use App\Workflow\Outcome;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;

/**
 * Шаг получения продажи («Менеджер забирает автомобиль» → «Автомобиль забрал», у гаража «Автомобиль передан») и вывоз в
 * логистике — одно событие (17.10.2026: вывоз — задача логистики, а не маршрут). Шаг проходит сам:
 * — менеджер сделки сам вывозит и нажал в логистике «Забрал» — машина у него;
 * — вывоз привёз машину в гараж менеджера сделки («Привёз» с точкой `keeper`);
 * — продажа пришла на шаг получения, а машина уже у него.
 * Конец пути гаражной ставит машину на «Подготовку» (`CloseGarageDeal`, `StartPrepOnArrival`).
 */
final class SyncHandover
{
    public function __construct(private TakeExit $take) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            TransportMoved::class => 'moved',
            StageEntered::class => 'entered',
        ];
    }

    public function moved(TransportMoved $e): void
    {
        $t = $e->transport;
        $offer = $t->saleOffer();
        $deal = $offer?->deal()->first();
        if (! $deal?->isActive()) {
            return;
        }
        $deal->setRelation('offer', $offer);
        $own = $deal->buyer_id !== null && $t->executor_id === $deal->buyer_id;
        $handed = $e->step === 'delivered' && $t->destination === Point::Keeper && $offer->keeper()?->id === $deal->buyer_id;
        if (($e->step === 'picked' && $own) || $handed) {
            $this->pressSale($deal, $e->by ?? $this->system());
        }
    }

    /** Продажа пришла на шаг получения, а машина уже у менеджера сделки. */
    public function entered(StageEntered $e): void
    {
        if ($e->track !== Track::Sale) {
            return;
        }
        $offer = $e->offer;
        $deal = $e->deal ?? $offer->deal()->first();
        if (! $deal?->isActive() || $offer->car_place !== CarPlace::Keeper || $offer->keeper()?->id !== $deal->buyer_id) {
            return;
        }
        $this->pressSale($deal->setRelation('offer', $offer), $e->by ?? $this->system());
    }

    private function pressSale(Deal $deal, User $by): void
    {
        /** @var Offer $offer */
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
