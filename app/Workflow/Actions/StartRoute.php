<?php

namespace App\Workflow\Actions;

use App\Offers\Offer;
use App\Park\Actions\RequestTowFromOffer;
use App\Users\User;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Support\Facades\DB;

/**
 * Поставить оффер на маршруты его вендора. Ветка, на которой оффер уже
 * стоит, не трогается: смена вендора не отматывает сделанную работу.
 * Без ветки запускаются только маршруты «с каждым предложением»; с веткой —
 * она одна, и по кнопке: так вывоз заводится там, где он исключение.
 */
final class StartRoute
{
    public function __construct(private EnterStage $enter) {}

    public function __invoke(Offer $offer, ?User $by = null, ?Track $track = null): Offer
    {
        $vendor = $offer->vendor;
        if (! $vendor || ! $vendor->is_active) {
            return $offer;
        }
        foreach ($track ? [$track] : Track::cases() as $t) {
            if ($offer->position($t)) {
                continue;
            }
            $workflow = $vendor->workflow($t);
            if (! $workflow?->is_active || (! $track && ! $workflow->auto_start) || ! ($start = $this->entry($offer, $workflow))) {
                continue;
            }
            $offer = DB::transaction(fn () => ($this->enter)($offer, $start, $by));
            // Вывоз — работа стоянки: с маршрутом заводится ТС «ожидается» и заявка на эвакуацию.
            if ($t === Track::Service && $by) {
                app(RequestTowFromOffer::class)($offer, $by);
            }
        }

        return $offer;
    }

    /**
     * Куда встать: на продаже — на первый этап своего состояния, иначе на
     * начало. Вендора могут назначить уже опубликованному предложению, и
     * стартовый черновик вернул бы его с витрины.
     */
    private function entry(Offer $offer, Workflow $workflow): ?Stage
    {
        $stages = $workflow->stages()->with(['exits', 'block'])->get();
        if ($workflow->track === Track::Sale) {
            $own = $stages->first(fn (Stage $s) => $s->offer_state === $offer->state);
            if ($own) {
                return $own;
            }
        }

        return $stages->first();
    }
}
