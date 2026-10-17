<?php

namespace App\Logistics\Actions;

use App\Logistics\Events\TransportRequested;
use App\Logistics\Executor;
use App\Logistics\Transport;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * «Нужен вывоз» — менеджер, что забирает сам, просит логистов (17.10.2026, владелец: «может запросить логистов забрать машину,
 * но только если попросит»). Его задача (скрытая из логистики, `Executor::Manager`) уходит «Без исполнителя», нет задачи —
 * заводится; админам — «Вывоз: …», дальше как обычно: админ выбирает, кто вывозит.
 */
final class RequestPickup
{
    public function __construct(private OpenPickup $open, private AssignExecutor $assign) {}

    public function __invoke(Offer $offer, User $by): Transport
    {
        $t = Transport::openFor($offer);
        if ($t?->state->picked()) {
            throw ValidationException::withMessages(['pickup' => 'ТС уже забрали']);
        }
        if ($t && $t->executor !== null && $t->executor !== Executor::Manager) {
            throw ValidationException::withMessages(['pickup' => 'Вывоз уже ведёт логистика']);
        }
        $t ??= ($this->open)($offer, $by, quiet: true);
        if ($t->executor === Executor::Manager) {
            $this->assign->release($t, $by);
        }
        $offer->log(OfferEventType::Note, $by, ['text' => 'Менеджер попросил вывоз в логистике']);
        TransportRequested::dispatch($t->refresh());

        return $t;
    }
}
