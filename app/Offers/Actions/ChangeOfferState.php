<?php

namespace App\Offers\Actions;

use App\Garage\Actions\ReceiveFromRoute;
use App\Garage\Car as GarageCar;
use App\Offers\BidState;
use App\Offers\DealState;
use App\Offers\Events\OfferPublished;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Users\User;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Requirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Единственная дверь для смены состояния оффера: проверка перехода, метки
 * времени, сделка, лента, событие. Если оффер идёт по маршруту и с текущего
 * этапа есть наш исход на этап с таким состоянием — маршрут догоняет кнопку.
 */
final class ChangeOfferState
{
    public function __invoke(Offer $offer, OfferState $next, ?User $by, bool $followRoute = true): Offer
    {
        return DB::transaction(function () use ($offer, $next, $by, $followRoute) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $from = $offer->state;
            // Гаражная сделка кончается не выдачей покупателю, а гаражом: конец маршрута («Сделка закрыта») ставит
            // машину менеджеру в гараж. Одна дверь — здесь, маршруты и их конечные этапы о гараже не знают.
            if ($next === OfferState::Delivered && $offer->deal()->first()?->isGarage()) {
                $next = OfferState::Garage;
            }

            if ($from === $next) {
                return $offer;
            }
            if (! $from->allows($next)) {
                throw ValidationException::withMessages(['state' => "Из «{$from->label()}» нельзя в «{$next->label()}»"]);
            }
            // Машину из гаража уводит только «Отдали по ошибке»: иначе строка гаража с расходами осталась бы без хозяина.
            if ($from === OfferState::Garage && GarageCar::where('offer_id', $offer->id)->exists()) {
                throw ValidationException::withMessages(['state' => 'Машина в гараже: сначала «Отдали по ошибке»']);
            }
            if ($next === OfferState::Open) {
                $this->readyToPublish($offer);
                OfferNumber::issue($offer);
                $offer->published_at ??= now();
                if (! $offer->bids_close_at || $offer->bids_close_at->isPast()) {
                    // Срок приёма по умолчанию — вечер, 20:00 (решение владельца 30.09.2026), а не текущая минута.
                    $offer->bids_close_at = now()->addDays((int) config('xcar.bids_window_days'))->setTime(20, 0);
                }
            }
            if ($next === OfferState::Gallery) {
                $this->readyForGallery($offer);
                OfferNumber::issue($offer);
                $offer->published_at ??= now();
            }

            $offer->state = $next;
            $offer->save();
            // Кто видит — до событий: уведомление о публикации берёт наступившие волны.
            app(SyncViewers::class)($offer);
            $offer->log(OfferEventType::StateChanged, $by, ['from' => $from->value, 'to' => $next->value]);
            $this->settleDeal($offer, $next, $by);

            OfferStateChanged::dispatch($offer, $by);
            if ($next === OfferState::Open) {
                OfferPublished::dispatch($offer, $by);
            }

            if ($followRoute && ($exit = $offer->stage()?->exitInto($next, $offer->deal()->first()))) {
                $offer = app(EnterStage::class)($offer, $exit->to, $by, [], $exit);
            }

            return $offer;
        });
    }

    /** В галерее без цены, но с чем показать: марка, модель, фото. */
    private function readyForGallery(Offer $offer): void
    {
        $missing = array_keys(array_filter([
            'марка' => ! $offer->brand_id,
            'модель' => ! $offer->model_id,
            'фотографии' => $offer->visiblePhotos()->isEmpty(),
        ]));
        if ($missing) {
            throw ValidationException::withMessages(['state' => 'Для галереи не хватает: '.implode(', ', $missing)]);
        }
    }

    private function readyToPublish(Offer $offer): void
    {
        $missing = array_keys(array_filter([
            'марка' => ! $offer->brand_id,
            'цена продажи' => ! $offer->asking_price,
            'фотографии' => $offer->visiblePhotos()->isEmpty(),
        ]));
        if ($missing) {
            throw ValidationException::withMessages(['state' => 'Для публикации не хватает: '.implode(', ', $missing)]);
        }
    }

    /**
     * Сделка живёт, пока оффер в сделке: выдан (гаражная — в гараже) — завершена, всё остальное — сорвалась. Резерв (остальные живые
     * подтверждения) ждёт до выдачи или архива и закрывается тихо, без уведомлений: снятие с продажи и черновик
     * его не трогают — к подтвердившим возвращаются, если выбранный передумал.
     */
    private function settleDeal(Offer $offer, OfferState $next, ?User $by = null): void
    {
        $deal = $offer->deal()->first();
        $garage = $next === OfferState::Garage && $deal?->isGarage();
        if ($garage || in_array($next, [OfferState::Delivered, OfferState::Archived], true)) {
            $offer->bids()->where('state', BidState::Active)->update(['state' => BidState::Declined, 'decided_at' => now(), 'decided_by' => $by?->id]);
        }
        if (! $deal || in_array($next, [OfferState::Sold], true)) {
            return;
        }
        if ($next === OfferState::Delivered || $garage) {
            $deal->update(['state' => DealState::Done, 'closed_at' => now()]);
            Requirement::where('deal_id', $deal->id)->whereNull('done_at')->update(['done_at' => now(), 'answer' => json_encode(['closed_by' => 'deal'])]);
            if ($garage) {
                app(ReceiveFromRoute::class)($deal, $by);
            }
        } else {
            app(CancelDeal::class)($deal, $by);
        }
        $offer->unsetRelation('deal');
    }
}
