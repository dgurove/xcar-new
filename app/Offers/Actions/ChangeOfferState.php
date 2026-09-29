<?php

namespace App\Offers\Actions;

use App\Garage\Car as GarageCar;
use App\Offers\BidState;
use App\Offers\DealState;
use App\Offers\Events\OfferPublished;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Offer;
use App\Offers\OfferEventType;
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
                $offer->published_at ??= now();
                if (! $offer->bids_close_at || $offer->bids_close_at->isPast()) {
                    $offer->bids_close_at = now()->addDays((int) config('xcar.bids_window_days'));
                }
            }
            if ($next === OfferState::Gallery) {
                $this->readyForGallery($offer);
                $offer->published_at ??= now();
            }

            $offer->state = $next;
            $offer->save();
            $offer->log(OfferEventType::StateChanged, $by, ['from' => $from->value, 'to' => $next->value]);
            $this->settleDeal($offer, $next, $by);

            OfferStateChanged::dispatch($offer, $by);
            if ($next === OfferState::Open) {
                OfferPublished::dispatch($offer, $by);
            }

            if ($followRoute && ($exit = $offer->stage()?->exitInto($next))) {
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
     * Сделка живёт, пока оффер в сделке: выдан — завершена, всё остальное — сорвалась. Резерв (остальные живые
     * подтверждения) ждёт до выдачи или архива и закрывается тихо, без уведомлений: снятие с продажи и черновик
     * его не трогают — к подтвердившим возвращаются, если выбранный передумал.
     */
    private function settleDeal(Offer $offer, OfferState $next, ?User $by = null): void
    {
        if (in_array($next, [OfferState::Delivered, OfferState::Archived], true)) {
            $offer->bids()->where('state', BidState::Active)->update(['state' => BidState::Declined, 'decided_at' => now(), 'decided_by' => $by?->id]);
        }
        $deal = $offer->deal()->first();
        if (! $deal || in_array($next, [OfferState::Sold], true)) {
            return;
        }
        if ($next === OfferState::Delivered) {
            $deal->update(['state' => DealState::Done, 'closed_at' => now()]);
            Requirement::where('deal_id', $deal->id)->whereNull('done_at')->update(['done_at' => now(), 'answer' => json_encode(['closed_by' => 'deal'])]);
        } else {
            app(CancelDeal::class)($deal, $by);
        }
        $offer->unsetRelation('deal');
    }
}
