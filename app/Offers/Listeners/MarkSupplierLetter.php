<?php

namespace App\Offers\Listeners;

use App\Live\RefreshDeal;
use App\Mail\Direction;
use App\Mail\Events\MessageFailed;
use App\Mail\Events\MessageParsed;
use App\Mail\Events\MessageSent;
use App\Mail\Extraction\Intent;
use App\Mail\Message;
use App\Mail\Scope;
use App\Notifications\SupplierLetterNotice;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Offers\OfferEvent;
use App\Offers\OfferEventType;
use App\Workflow\Position;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Events\Dispatcher;

/**
 * Письмо поставщику ушло (11.10.2026, начальник: «не понимаю, мы реально отправили письмо и ждём или ещё не отправили»):
 * наше письмо в ветке предложения с ящика продаж, а сделка стоит на этапе с письмом (`Stage::isLetterStep`) и ждёт нас —
 * ход переходит поставщику, сутки на ответ считаются от письма, в «Истории» — «Письмо поставщику отправлено».
 *
 * Доставка в шаге (18.10.2026, владелец): не ушло с нашей стороны (`MessageFailed`) — `payload.letter_failed`, шаг красным
 * «Письмо не ушло» с «Повторить»; вернулся отказ почтового сервера (`Intent::Bounce` в ветке ушедшего письма) —
 * `payload.letter_bounced`, красным «Не доставлено» с «Написать снова». Обоим — уведомление тому, кто писал.
 */
final class MarkSupplierLetter
{
    public function subscribe(Dispatcher $events): array
    {
        return [MessageSent::class => 'sent', MessageFailed::class => 'failed', MessageParsed::class => 'bounced'];
    }

    public function sent(MessageSent $e): void
    {
        $position = self::position($e->message);
        if (! $position) {
            return;
        }
        // Ответили поставщику на его письмо — снова ждём его (28.10.2026: «Разобрать ответ поставщика» висело после двух
        // «Да, актуально», наше письмо при `replied` не засчитывалось).
        if ($position->letterStatus() === 'replied') {
            if ($position->replyHandled($e->message)) {
                $position->offer->log(OfferEventType::SupplierLetter, $e->message->author, ['letter' => $e->message->id, 'template_id' => $position->stage->template_id]);
            }

            return;
        }
        if (! in_array($position->letterStatus(), ['unsent', 'failed', 'bounced'], true)) {
            return;
        }
        // Доп. запрос поставщику (17.11.2026, `Offers\ExtraRequest`: «прислать ПТС и согласовать осмотр») — не письмо этапа:
        // иначе «Подтверждаем актуальность» стояло бы «ушло», хотя ушёл только запрос ПТС.
        if (! empty($e->message->requests)) {
            return;
        }
        $offer = $position->offer;
        $message = $e->message;
        $at = $message->sent_at ?? $message->date_at ?? now();
        $payload = array_diff_key($position->payload ?? [], array_flip(['letter_failed', 'letter_bounced']));
        $position->update([
            'waits_for' => null,
            'deadline_at' => $position->stage->deadlineFor($offer, $at->copy()),
            'reminded_at' => null,
            'overdue_at' => null,
            'payload' => [...$payload, 'letter_id' => $message->id, 'letter_at' => $at->toIso8601String()],
        ]);
        $offer->log(OfferEventType::SupplierLetter, $message->author, ['letter' => $message->id, 'template_id' => $position->stage->template_id]);
        // Шаг на открытой странице — сразу «Письмо ушло» (15.11.2026, начальник не был уверен, что ушло).
        RefreshDeal::of($offer->deal);
        // Письмо «перевыставить счёт» ушло (`TakeParallel`) — дальше у шага снова обычный шаблон.
        if ($position->stage->isRequisitesStep()) {
            Deal::where('offer_id', $offer->id)->active()->whereNotNull('reissue_at')->update(['reissue_at' => null]);
        }
    }

    /**
     * Письмо этого шага уже ушло (19.11.2026, владелец по AUT-26-1109653: «письмо ушло 6 часов назад, а шаг снова просит —
     * постоянно эта проблема, переделать по уму»): отметка «ушло» жила в позиции и стиралась любым новым входом на этап —
     * «Отдать» другому менеджеру, смена ветки гаража, «Назад» и снова вперёд. Теперь письмо — факт машины: при входе на этап с
     * письмом ищем последнее наше письмо поставщику того же шаблона (`supplier_letter` в истории; шаблон — в событии, у старых
     * — по этапу, на котором засчитали) и засчитываем, если с тех пор разговор с поставщиком не начинался заново: не было его
     * ответа, отказа, переоценки, ухода машины из продажи (`OfferEvent::supplierTalkBroken`, то же правило, что у
     * `Give::agreedBefore`). Письмо не ушло (сбой, удалено) — не засчитывается.
     */
    public static function already(Offer $offer, Stage $to, ?Position $before = null): ?Message
    {
        if (! $to->template_id) {
            return null;
        }
        // Письмо с реквизитами — про покупателя этой сделки: другой сделке оно не годится, а «перевыставить счёт» (`reissue_at`)
        // пишется заново. Письмо о покупке — про машину, переходит и к другому менеджеру.
        $deal = $offer->deal()->first();
        if ($to->isRequisitesStep() && (! $deal || $deal->reissue_at)) {
            return null;
        }
        $since = $to->isRequisitesStep() ? $deal->created_at : null;
        $fits = fn (?Message $m) => $m && (! $since || ($m->sent_at ?? $m->date_at ?? now())->gte($since)) ? $m : null;
        // Позиция до входа уже знала письмо этого шаблона (повторный вход на тот же этап; у старых сделок события письма нет).
        if ($before && ($id = $before->payload['letter_id'] ?? null) && $before->stage?->template_id === $to->template_id
            && ($at = $before->payload['letter_at'] ?? null)) {
            $after = (int) OfferEvent::where('offer_id', $offer->id)->where('created_at', '<', $at)->max('id');
            $message = OfferEvent::supplierTalkBroken($offer->id, $after, replies: true) ? null
                : Message::whereKey($id)->where('direction', Direction::Out->value)->first();
            if ($message = $fits($message)) {
                return $message;
            }
        }
        $event = OfferEvent::where('offer_id', $offer->id)->where('type', OfferEventType::SupplierLetter->value)->latest('id')->first();
        if (! $event || OfferEvent::supplierTalkBroken($offer->id, $event->id, replies: true)) {
            return null;
        }
        $entered = $event->payload['template_id'] ?? null ? null : OfferEvent::where('offer_id', $offer->id)->where('type', OfferEventType::StageEntered->value)
            ->where('id', '<', $event->id)->latest('id')->first();
        $template = $event->payload['template_id'] ?? Stage::whereKey($entered?->payload['to_id'] ?? 0)->value('template_id');
        if ((int) $template !== (int) $to->template_id) {
            return null;
        }

        return $fits(Message::whereKey($event->payload['letter'] ?? 0)->where('direction', Direction::Out->value)
            ->where(fn ($q) => $q->whereNull('send_state')->orWhere('send_state', '!=', 'failed'))->first());
    }

    /**
     * Письмо по шаблону шага ушло раньше шага (19.11.2026: у Т-Страхования «готовность к покупке» пишут и до «Беру в работу»):
     * сделка стоит не на этапе с письмом — `sent` его не засчитывает, поэтому событие пишется здесь, с шаблоном; войдёт на
     * шаг — `already` его найдёт. Только шаблон, что стоит на этапе маршрута этой машины.
     */
    public static function early(Message $message, int $offerId, int $templateId): void
    {
        $offer = Offer::find($offerId);
        $position = $offer?->position(Track::Sale);
        if (! $offer?->deal()->exists() || ! $position || $position->stage->isLetterStep() || ! empty($message->requests)) {
            return;
        }
        if (! Stage::where('workflow_id', $position->stage->workflow_id)->where('template_id', $templateId)->exists()) {
            return;
        }
        $offer->log(OfferEventType::SupplierLetter, $message->author, ['letter' => $message->id, 'template_id' => $templateId, 'early' => true]);
    }

    /** Не ушло: шаг красным; уведомление — один раз на письмо («Повторить» с тем же сбоем не звенит снова). */
    public function failed(MessageFailed $e): void
    {
        $position = self::position($e->message);
        // Не ушёл доп. запрос — письмо этапа от этого «не ушло» не становится: окно запроса скажет само.
        if (! $position || ! empty($e->message->requests) || ! in_array($position->letterStatus(), ['unsent', 'failed', 'bounced'], true)) {
            return;
        }
        $again = ($position->payload['letter_failed']['id'] ?? null) === $e->message->id;
        $position->update(['payload' => [...($position->payload ?? []), 'letter_failed' => ['id' => $e->message->id, 'error' => self::reason((string) $e->message->send_error)]]]);
        RefreshDeal::of($position->offer->deal);
        if (! $again) {
            $e->message->author?->notify(new SupplierLetterNotice($position->offer, 'failed'));
        }
    }

    /**
     * Отказ почтового сервера на наше письмо этого шага. Отказ приходит своей темой («Undelivered Mail…», «Не доставлено»)
     * и часто своей веткой — письмо ищем по его Message-ID в заголовках и теле отказа, иначе по ветке, иначе по адресату и
     * теме; только отказ новее письма и не из истории ящика (`quiet`, перечитывание) — старый отказ не красит новое письмо.
     */
    public function bounced(MessageParsed $e): void
    {
        $m = $e->message;
        if ($e->quiet || $m->direction !== Direction::In || $m->intent !== Intent::Bounce->value || $m->account?->scope !== Scope::Offers) {
            return;
        }
        $letter = self::bouncedLetter($m);
        $position = $letter ? self::position($letter) : null;
        if (! $position || $position->letterStatus() !== 'sent' || ($position->payload['letter_id'] ?? null) !== $letter->id) {
            return;
        }
        $position->update(['waits_for' => WaitsFor::Us, 'payload' => [...$position->payload, 'letter_bounced' => ['at' => now()->toIso8601String(), 'to' => Intent::bounceRecipient($m->text_body ?: strip_tags((string) $m->html_body)) ?? $letter->to_preview]]]);
        $letter->author?->notify(new SupplierLetterNotice($position->offer, 'bounced'));
    }

    /** Наше письмо, на которое пришёл отказ: за три дня до него, тем же ящиком. */
    public static function bouncedLetter(Message $bounce): ?Message
    {
        $at = $bounce->date_at ?? now();
        $text = implode("\n", [$bounce->in_reply_to, $bounce->references_header, $bounce->text_body, $bounce->html_body]);
        $to = Intent::bounceRecipient($bounce->text_body ?: strip_tags((string) $bounce->html_body));
        $letters = Message::where('account_id', $bounce->account_id)->where('direction', Direction::Out)
            ->whereBetween('date_at', [$at->copy()->subDays(3), $at])->latest('date_at')->get();

        return $letters->first(fn (Message $l) => $l->message_id && str_contains($text, trim($l->message_id, '<>')))
            ?? $letters->first(fn (Message $l) => $l->thread_id === $bounce->thread_id)
            ?? ($to ? $letters->first(fn (Message $l) => str_contains(mb_strtolower((string) $l->to_preview), $to) && $l->subject && str_contains($text, $l->subject)) : null);
    }

    /** Позиция сделки, которой адресовано наше письмо из ветки предложения с ящика продаж. */
    private static function position(Message $message): ?Position
    {
        $message->loadMissing(['account', 'thread.offer.deal']);
        $offer = $message->thread?->offer;
        if ($message->direction !== Direction::Out || $message->account?->scope !== Scope::Offers || ! $offer?->deal?->isActive()) {
            return null;
        }
        $position = $offer->unsetRelation('positions')->position(Track::Sale);
        $position?->setRelation('offer', $offer);

        return $position;
    }

    /** Причина словами: «Mailbox unavailable», «Connection timed out» — первая строка без кодов. */
    private static function reason(string $error): string
    {
        $line = trim((string) strtok($error, "\n"));

        return mb_substr(preg_replace('/^(Expected response code \S+ but got code "\d+", with message ")?/u', '', $line), 0, 160);
    }
}
