<?php

namespace App\Offers\Listeners;

use App\Offers\Actions\PickSigningTime;
use App\Offers\Signing;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;

/**
 * «Время подписания» проходит само, если менеджер назвал день и время заранее, пока поставщик готовил договор (11.11.2026,
 * `SaveSigningWish`, `deals.signing_wish`): «Время согласовано» с этим временем — шаг сразу на «Запись», ход наш, письмо
 * «запишите на …». Не после «Поставщик предложил другое время» (у сделки слоты — выбирает менеджер) и не откатом. Стоит до
 * `Notify`: просьба уже закрыта, «Ваш ход» и сообщение в чат менеджеру не уходят.
 */
final class ApplySigningWish
{
    public function subscribe(Dispatcher $events): array
    {
        return [StageEntered::class => 'entered'];
    }

    public function entered(StageEntered $e): void
    {
        $deal = $e->deal ?? $e->offer->deal;
        if ($e->track !== Track::Sale || ! $deal?->isActive() || $deal->signing_at || $deal->signing_slots
            || ! Signing::picks($e->to->loadMissing('exits')) || ! ($at = Signing::wishAt($deal))) {
            return;
        }
        $offer = $e->offer->fresh();
        if ($offer->position(Track::Sale)?->stage_id !== $e->to->id) {
            return;
        }
        app(PickSigningTime::class)($deal->setRelation('offer', $offer), $e->by ?? $deal->buyer, $at->format('Y-m-d H:i'), staff: true);
    }
}
