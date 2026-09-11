<?php

namespace App\Offers\Actions;

use App\Offers\Events\OfferPublished;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Единственная дверь для смены состояния оффера: проверка перехода, метки времени, лента, событие. */
final class ChangeOfferState
{
    public const BIDS_WINDOW_DAYS = 3;

    public function __invoke(Offer $offer, OfferState $next, User $by): Offer
    {
        return DB::transaction(function () use ($offer, $next, $by) {
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
                    $offer->bids_close_at = now()->addDays(self::BIDS_WINDOW_DAYS);
                }
            }
            if ($next === OfferState::Gallery) {
                $offer->published_at ??= now();
            }

            $offer->state = $next;
            $offer->save();
            $offer->log(OfferEventType::StateChanged, $by, ['from' => $from->value, 'to' => $next->value]);

            OfferStateChanged::dispatch($offer, $by);
            if ($next === OfferState::Open && $from !== OfferState::Closed) {
                OfferPublished::dispatch($offer, $by);
            }

            return $offer;
        });
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
}
