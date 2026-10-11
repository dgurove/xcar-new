<?php

namespace App\Offers\Actions;

use App\Offers\Estimate;
use App\Offers\EstimateState;
use App\Offers\Events\EstimateTaken;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Взять»: оценка менеджера — цена продажи, машина уходит в «Оцененные», как после «Оценить». Остальные просьбы и запрет
 * шеринга, поставленный оценкой, снимает `UpdateOffer` → `CloseEstimates`.
 */
final class TakeEstimate
{
    public function __construct(private UpdateOffer $update) {}

    public function __invoke(Estimate $estimate, User $by): Offer
    {
        if (! $estimate->isGiven()) {
            throw ValidationException::withMessages(['asking_price' => 'Оценки уже нет']);
        }

        $offer = DB::transaction(function () use ($estimate, $by) {
            $estimate->update(['state' => EstimateState::Taken]);
            $estimate->offer->log(OfferEventType::Estimate, $by, ['do' => 'taken', 'user' => $estimate->user_id, 'amount' => $estimate->amount]);

            return ($this->update)($estimate->offer, ['asking_price' => $estimate->amount], $by);
        });
        EstimateTaken::dispatch($estimate, $by);

        return $offer;
    }
}
