<?php

namespace App\Offers\Listeners;

use App\Mail\Direction;
use App\Mail\Events\MessageParsed;
use App\Mail\Events\MessageSent;
use App\Mail\Message;
use App\Offers\Deal;
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
 *   реализацию, подтвердите актуальность» — запрос к нам, наш ответ на него и есть разбор;
 * - ответил из своей почты с копией на наш ящик (`MessageParsed`, 23.11.2026: Катя ведёт Совкомбанк с личного адреса, deal@ — в
 *   копии; письмо входящее, но наше — `Message::isOurs`, а разбор висел: из 35 разборов Совкома 7 уже были отвечены так).
 * Шаг менеджера разбор не гасит — менеджер письма не видел. В «Истории» — чем разобрано.
 */
final class CloseReviews
{
    public function subscribe(Dispatcher $events): array
    {
        return [StageEntered::class => 'step', MessageSent::class => 'answered', MessageParsed::class => 'staffMail'];
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
        $deal = Deal::where('offer_id', $offer->id)->active()->first();
        $at = $m->sent_at ?? $m->date_at ?? now();
        $deal && self::close($offer, ReplyReview::pending()->where('deal_id', $deal->id)
            ->whereHas('message', fn ($q) => $q->where('thread_id', $m->thread_id)->where('date_at', '<=', $at)), $by, 'ответом поставщику');
    }

    public function staffMail(MessageParsed $e): void
    {
        $m = $e->message;
        if ($m->direction !== Direction::In || ! ($by = self::staffSender($m)) || ! ($offer = $m->thread?->offer)) {
            return;
        }
        $deal = Deal::where('offer_id', $offer->id)->active()->first();
        $deal && self::close($offer, ReplyReview::pending()->where('deal_id', $deal->id)
            ->whereHas('message', fn ($q) => $q->where('thread_id', $m->thread_id)->where('date_at', '<=', $m->date_at ?? now())->whereKeyNot($m->id)),
            $by, 'ответом поставщику из почты');
    }

    /**
     * Сотрудник, что написал письмо со своего адреса (адрес — его почта в профиле). Пересланное письмо поставщика — не ответ:
     * ни пересылка по заголовку `Sender` (`isForwardedByStaff`), ни «Fwd:» в теме, ни «Пересылаемое сообщение» первой строкой.
     */
    public static function staffSender(Message $m): ?User
    {
        $email = mb_strtolower(trim((string) $m->from_email));
        if ($email === '' || ! in_array($email, Message::ownEmails(), true) || $m->isForwardedByStaff()
            || preg_match('/^\s*(?:fwd?|пересл)/iu', (string) $m->subject)
            // Пересылка с mail.ru тему не меняет: «-------- Пересылаемое сообщение --------» в начале тела.
            || preg_match('/^\s*(?:-{3,}\s*)?(?:Пересылаемое сообщение|Forwarded message|Original Message|Исходное сообщение)/iu', $m->replyText())) {
            return null;
        }
        $user = User::whereRaw('lower(email) = ?', [$email])->first();

        return $user?->isStaff() ? $user : null;
    }

    private static function close(Offer $offer, $reviews, User $by, string $how): void
    {
        $done = ReplyReview::finish($reviews, $by);
        $done && $offer->log(OfferEventType::Note, $by, ['text' => ($done > 1 ? 'Письма разобраны ' : 'Письмо разобрано ').$how]);
    }
}
