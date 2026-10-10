<?php

namespace App\Logistics\Actions;

use App\Logistics\Transport;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Users\User;

/**
 * «В логистику» из CRM или из дела ТС на парковке: открытая перевозка у машины одна — повторная отправка её правит.
 * Подготовка со страховой (звонок, документы, дата) остаётся маршрутом вывоза в CRM; здесь — только перевозка A → B.
 */
final class SendToLogistics
{
    public function __construct(private SaveTransport $save) {}

    public function __invoke(Offer|Vehicle $car, array $data, User $by): Transport
    {
        $t = Transport::openFor($car) ?? new Transport([$car instanceof Offer ? 'offer_id' : 'vehicle_id' => $car->id]);
        $fresh = ! $t->exists;
        $t = ($this->save)($t, $data, $by);

        $line = 'Логистика: '.$t->scope->label().($t->executorName() ? ', везёт '.$t->executorName() : ', ждёт логиста');
        if ($fresh) {
            $car instanceof Offer
                ? $car->log(OfferEventType::Note, $by, ['text' => $line])
                : $car->log(EventType::Note, $by, ['text' => $line]);
        }

        return $t;
    }
}
