<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Offers\Actions\ChangeOfferState;
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

        DB::transaction(function () use ($car, $by) {
            $offer = $car->offer;
            $car->delete();
            ($this->state)($offer, OfferState::Draft, $by);
        });
    }
}
