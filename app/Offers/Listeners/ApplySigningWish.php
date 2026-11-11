<?php

namespace App\Offers\Listeners;

use App\Offers\Actions\SaveSigningWish;
use App\Offers\Signing;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;

/**
 * «Время подписания» проходит само, если менеджер назвал дни и время заранее, пока поставщик готовил договор (11.11.2026,
 * `SaveSigningWish`, `deals.signing_wish`): «Время согласовано» — шаг сразу на «Запись», ход наш, письмо «запишите …».
 * Не после «Поставщик предложил другое время» (у сделки слоты — выбирает менеджер) и не откатом. Стоит до `Notify`: просьба
 * уже закрыта, «Ваш ход» и сообщение в чат менеджеру не уходят.
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
            || ! Signing::picks($e->to->loadMissing('exits')) || ! Signing::wish($deal)) {
            return;
        }
        $offer = $e->offer->fresh();
        if ($offer->position(Track::Sale)?->stage_id !== $e->to->id) {
            return;
        }
        app(SaveSigningWish::class)->pass($deal->setRelation('offer', $offer), $e->by, staff: true);
    }
}
