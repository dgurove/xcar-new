<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportMoved;
use App\Logistics\Transport;
use App\Logistics\TransportState;
use App\Media\PhotoIngest;
use App\Offers\OfferEventType;
use App\Park\Actions\Transfer;
use App\Park\VehicleState;
use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Track;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Сдал»: машина в точке B, кадры выгрузки. Предложение CRM проходит этап «Вывоз и осмотр» дальше — «Стоит у
 * менеджера» или «Стоит у нас» (куда — выбор CRM, `Destination`); маршрут ещё не дошёл до вывоза — отмечаем строкой,
 * дальше сотрудник сам. ТС парковки, привезённую на парковку другого города, переводим туда (`Park\Actions\Transfer`).
 */
final class Deliver
{
    public function __construct(private PhotoIngest $photos, private TakeExit $take, private Transfer $transfer) {}

    /** @param  list<UploadedFile>  $files */
    public function __invoke(Transport $t, User $by, array $files = []): Transport
    {
        if (! $t->isExecutedBy($by) && ! $by->seesAllTransports()) {
            throw ValidationException::withMessages(['transport' => 'Перевозка поручена не вам']);
        }
        if (! in_array($t->state, [TransportState::Assigned, TransportState::Moving], true)) {
            throw ValidationException::withMessages(['transport' => 'Перевозка '.mb_strtolower($t->state->label())]);
        }
        foreach ($files as $file) {
            $this->photos->fromUpload($t, 'photos', $file, ['at' => 'delivery']);
        }

        DB::transaction(function () use ($t, $by) {
            $t->update(['state' => TransportState::Delivered, 'picked_at' => $t->picked_at ?? now(), 'delivered_at' => now()]);
            if ($offer = $t->offer) {
                $exit = $offer->position(Track::Service)?->stage->exitsFor(Actor::Keeper, $offer->pickupDestination())->first();
                $exit
                    ? ($this->take)($offer, $exit, Actor::Keeper, $by)
                    : $offer->log(OfferEventType::Note, $by, ['text' => 'Логистика: машину сдали'.($t->to_address ? ', '.$t->to_address : '')]);
            }
            $vehicle = $t->vehicle;
            $yard = $t->toYard;
            if ($vehicle && $yard && $vehicle->state === VehicleState::Stored && (int) $yard->settlement_id !== (int) $vehicle->city_id) {
                ($this->transfer)($vehicle, $by, $yard, null, now(), 'Логистика');
            }
        });
        TransportMoved::dispatch($t, $by);

        return $t;
    }
}
