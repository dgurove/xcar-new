<?php

namespace App\Billing\Actions;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\PayOffer;
use App\Chats\Actions\OpenChat;
use App\Chats\Actions\PlatformMessage;
use App\Notifications\InvoiceNudgeNotice;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Carbon\CarbonInterface;

/**
 * «Напомнить менеджеру» про отправленный счёт (19.11.2026, владелец: «снова ему уведомление и сообщение в чат и в Telegram: отправили
 * счёт такой-то тогда-то, прошло N дней, просим прикрепить платёжное поручение в сделке»). Сейчас (`now`) или по плану
 * (`later` — `nudge_at`, шлёт `billing:nudges`); «Не напоминать» снимает план (`cancel`). Шлёт только отправленный неоплаченный
 * счёт сделки без заявленной менеджером оплаты — иначе план гаснет молча.
 */
final class NudgeInvoice
{
    /** Есть о чём напоминать: счёт сделки у менеджера (отправлен или по нему уже платили), к оплате, его платёжки ещё нет. */
    public static function allows(Invoice $invoice): bool
    {
        return $invoice->deal_id && $invoice->direction === 'issued' && $invoice->isShownToManager() && $invoice->state === InvoiceState::Issued
            && $invoice->remaining() > 0 && ! $invoice->claims()->exists();
    }

    /** Когда последний раз писали менеджеру про этот счёт: отправка или напоминание. */
    public static function lastAt(Invoice $invoice): ?CarbonInterface
    {
        return collect([$invoice->shown_at ?? $invoice->issued_at, $invoice->nudged_at])->filter()->sortDesc()->first();
    }

    /** Текст напоминания — в чат, уведомление и Telegram одним текстом. */
    public static function text(Invoice $invoice): string
    {
        $days = (int) floor(($invoice->shown_at ?? $invoice->issued_at ?? now())->diffInDays(now()));
        $ago = $days < 1 ? 'сегодня' : 'прошло '.$days.' '.\App\Support\Plural::of($days, ['день', 'дня', 'дней']);
        if ($invoice->isDocument()) {
            return 'Отправили '.mb_lcfirst(PayOffer::invoiceName($invoice)).' от '.$invoice->issued_at->format('d.m').' на '.Money::rub(PayOffer::amount($invoice))
                .', '.$ago.'. Просим приложить платёжное поручение в сделке';
        }
        $link = $invoice->openLink();

        return 'Отправили ссылку на оплату '.PayOffer::what($invoice).' '.Money::rub(PayOffer::amount($invoice)).' '.($invoice->shown_at ?? $invoice->issued_at)?->format('d.m').', '.$ago
            .'. Просим оплатить'.($link ? ' по ссылке: '.$link->url() : '');
    }

    public function now(Invoice $invoice, User $by): bool
    {
        $invoice = $invoice->fresh(['deal.offer', 'deal.buyer']);
        $deal = $invoice->deal;
        if (! self::allows($invoice) || ! $deal?->buyer) {
            $invoice->nudge_at && $invoice->forceFill(['nudge_at' => null])->save();

            return false;
        }
        $text = self::text($invoice);
        $deal->buyer->notify(new InvoiceNudgeNotice($deal, $invoice, $text));
        rescue(fn () => app(PlatformMessage::class)(app(OpenChat::class)($deal->offer, $deal->buyer, greet: false), $text), report: true);
        $invoice->forceFill(['nudged_at' => now(), 'nudge_at' => null])->save();
        $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Напомнили менеджеру: '.mb_lcfirst(PayOffer::name($invoice))]);
        \App\Live\RefreshDeal::of($deal);

        return true;
    }

    /** Напомнить через `$days` дней в 10:00 по Москве. */
    public function later(Invoice $invoice, int $days, User $by): void
    {
        $at = now('Europe/Moscow')->addDays(max(1, $days))->setTime(10, 0);
        $invoice->forceFill(['nudge_at' => $at])->save();
        $invoice->deal?->offer?->log(OfferEventType::Note, $by, ['text' => 'Напомним менеджеру '.$at->format('d.m').': '.mb_lcfirst(PayOffer::name($invoice))]);
        $invoice->deal && \App\Live\RefreshDeal::of($invoice->deal);
    }

    public function cancel(Invoice $invoice, User $by): void
    {
        if ($invoice->nudge_at) {
            $invoice->forceFill(['nudge_at' => null])->save();
            $invoice->deal?->offer?->log(OfferEventType::Note, $by, ['text' => 'Напоминание менеджеру снято: '.mb_lcfirst(PayOffer::name($invoice))]);
            $invoice->deal && \App\Live\RefreshDeal::of($invoice->deal);
        }
    }

    /** Часы (`billing:nudges`): наступившие напоминания по плану. */
    public function due(): int
    {
        $n = 0;
        foreach (Invoice::whereNotNull('nudge_at')->where('nudge_at', '<=', now())->get() as $invoice) {
            $n += (int) $this->now($invoice, \App\Billing\Robot::user());
        }

        return $n;
    }
}
