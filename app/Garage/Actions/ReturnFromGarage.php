<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Offers\Actions\CancelDeal;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\DealState;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Отдали по ошибке»: строка гаража стирается, предложение возвращается в черновик.
 * Пока по машине есть расходы или продажа — нет: это уже не ошибка, а история.
 */
final class ReturnFromGarage
{
    public function __construct(private ChangeOfferState $state) {}

    public function __invoke(Car $car, User $by): void
    {
        if ($car->isSold() || $car->costs()->exists()) {
            throw ValidationException::withMessages(['car' => 'По ТС уже есть расходы или продажа']);
        }
        // Сделка со страховой ещё идёт — её отменяет «Отказываюсь» или отмена сделки в CRM, а не «Отдали по ошибке».
        if ($car->dealOpen()) {
            throw ValidationException::withMessages(['car' => 'Идёт сделка со страховой: отмените её']);
        }

        DB::transaction(function () use ($car, $by) {
            $offer = $car->offer;
            // Пришла гаражной сделкой — та отменяется: в истории менеджера не остаётся «завершённой» сделки без машины.
            if ($car->deal && $car->deal->state !== DealState::Cancelled) {
                app(CancelDeal::class)($car->deal, $by, keepCar: true);
            }
            $car->delete();
            ($this->state)($offer, OfferState::Draft, $by);
        });
    }
}
