<?php

namespace App\Offers\Actions;

use App\Mail\Message;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use App\Workflow\Outcome;
use App\Workflow\Track;

/**
 * Заявка поставщику ушла (09.10.2026, Каркаде): каждая сделка списка, что ещё ждёт заявки, идёт дальше исходом «Заявка
 * отправлена» — письмо в её журнале. Ветка письма — вендора и без автопривязки (`unlinked_at`): ответ на список
 * отмечают «Ответом по списку», а не шагом первой сделки, чей ДЛ нашёлся в письме.
 */
final class MarkSupplierRequestSent
{
    public function __construct(private TakeExit $take) {}

    /** @param  list<int>  $ids  сделки, что попали в файл заявки */
    public function __invoke(array $ids, Message $message, User $by): int
    {
        $moved = 0;
        $vendorId = null;
        foreach (Deal::whereKey($ids)->where('state', DealState::Active)->with('offer.positions.stage.exits.to')->get() as $deal) {
            $exit = $deal->offer->position(Track::Sale)?->stage->exitsFor(Actor::Staff, $deal)->first(fn (Outcome $e) => mb_strtolower($e->label) === 'заявка отправлена');
            if ($exit) {
                ($this->take)($deal->offer, $exit, Actor::Staff, $by, ['letter' => $message->id]);
                $vendorId ??= $deal->offer->vendor_id;
                $moved++;
            }
        }
        $message->thread?->update(['vendor_id' => $vendorId ?? $message->thread->vendor_id, 'offer_id' => null, 'unlinked_at' => now()]);

        return $moved;
    }
}
