<?php

namespace App\Offers\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\InvoiceState;
use App\Offers\Bid;
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

    /** Сделка живёт, пока оффер в сделке: выдан — завершена, всё остальное — сорвалась. */
    private function settleDeal(Offer $offer, OfferState $next, ?User $by = null): void
    {
        $deal = $offer->deal()->first();
        if (! $deal || in_array($next, [OfferState::Sold], true)) {
            return;
        }
        $state = $next === OfferState::Delivered ? DealState::Done : DealState::Cancelled;
        $deal->update(['state' => $state, 'closed_at' => now()]);
        if ($state === DealState::Cancelled && $deal->bid?->state === BidState::Accepted) {
            Bid::whereKey($deal->bid_id)->update(['state' => BidState::Declined]);
        }
        Requirement::where('deal_id', $deal->id)->whereNull('done_at')->update(['done_at' => now(), 'answer' => json_encode(['closed_by' => 'deal'])]);
        // Сделка сорвалась — невыплаченное вознаграждение гаснет; выплаченное остаётся историей.
        if ($state === DealState::Cancelled && ($fee = $deal->agentFee()->first()) && $fee->state === InvoiceState::Issued && $fee->paid == 0) {
            app(VoidInvoice::class)($fee, $by, 'Сделка отменена');
        }
        $offer->unsetRelation('deal');
    }
}
