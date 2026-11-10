<?php

namespace App\Offers\Listeners;

use App\Mail\Direction;
use App\Mail\Events\MessageSent;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\ReplyReview;
use App\Users\User;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;
use Illuminate\Events\Dispatcher;

/**
 * Письмо поставщика разобрано не только кнопками плашки (10.11.2026, начальник по Volvo 01527: сделка стояла «Ваш ход →
 * Разобрать ответ», хотя письмо в Альфу ушло и ждали её). На проде из 29 разборов кнопками закрыли 4: письмо разбирают делом —
 * нажимают шаг или отвечают поставщику, — а разбор висел и держал «наш ход» поверх любого этапа (`Position::replyPending`).
 * Теперь разобрано, когда сотрудник:
 * - сдвинул шаг сделки после письма (`StageEntered` с ним; шаг из «Показать менеджеру» закрывает `Disclose` сам);
 * - ответил поставщику в той же ветке после письма (`MessageSent`) — любым письмом, не только письмом шага: «ГОТС поступили на
 *   реализацию, подтвердите актуальность» — запрос к нам, наш ответ на него и есть разбор.
 * Шаг менеджера разбор не гасит — менеджер письма не видел. В «Истории» — чем разобрано.
 */
final class CloseReviews
{
    public function subscribe(Dispatcher $events): array
    {
        return [StageEntered::class => 'step', MessageSent::class => 'answered'];
    }

    public function step(StageEntered $e): void
    {
        if ($e->track !== Track::Sale || $e->letter || ! $e->by?->isStaff()) {
            return;
        }
        $deal = $e->deal ?? $e->offer->deal;
        $deal && self::close($e->offer, ReplyReview::pending()->where('deal_id', $deal->id), $e->by, 'шагом «'.($e->exit?->label ?? $e->to->name).'»');
    }

    public function answered(MessageSent $e): void
    {
        $m = $e->message;
        if ($m->direction !== Direction::Out || ! ($by = $m->author) || ! $by->isStaff() || ! ($offer = $m->thread?->offer)) {
            return;
        }
        $deal = Deal::where('offer_id', $offer->id)->where('state', DealState::Active)->first();
        $at = $m->sent_at ?? $m->date_at ?? now();
        $deal && self::close($offer, ReplyReview::pending()->where('deal_id', $deal->id)
            ->whereHas('message', fn ($q) => $q->where('thread_id', $m->thread_id)->where('date_at', '<=', $at)), $by, 'ответом поставщику');
    }

    private static function close(Offer $offer, $reviews, User $by, string $how): void
    {
        $done = $reviews->update(['state' => ReplyReview::DONE, 'done_by' => $by->id, 'done_at' => now()]);
        $done && $offer->log(OfferEventType::Note, $by, ['text' => ($done > 1 ? 'Письма разобраны ' : 'Письмо разобрано ').$how]);
    }
}
