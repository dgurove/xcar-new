<?php

namespace App\Http\Admin;

use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Billing\Invoice;
use App\Offers\OfferNumber;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ссылки на оплату в «Оплатах»: откуда (сделка, гараж, вручную — им же чип «Откуда» у «Нам должны»), кто взял, ТС, слово
 * состояния и фраза для карточки ссылки. Своего списка у ссылок нет с 17.11.2026 (была вкладка «По ссылкам», 09.10.2026):
 * счёт со ссылкой стоит в «Нам должны», что с ней — словом в строке (`MoneyRows::linkWord`).
 */
final class MoneyLinks
{
    /** Откуда ссылка: разовая (кнопкой в «Оплатах»), счёт сделки, машина гаража. */
    public const FROM = "(case when billing_invoices.kind = 'service' and billing_invoices.vehicle_id is null then 'manual' when billing_invoices.deal_id is not null then 'deal' else 'garage' end)";

    public const FROM_LABELS = ['deal' => 'Сделка', 'garage' => 'Гараж', 'manual' => 'Вручную'];

    public const WITH = ['invoice.party', 'invoice.charges', 'invoice.offer', 'invoice.deal.offer', 'invoice.deal.buyer',
        'invoice.garageCar.offer', 'invoice.garageCar.manager', 'invoice.creditDeal.offer', 'invoice.creditDeal.buyer', 'invoice.creator', 'attempts', 'creator', 'payerUser'];

    /** Ссылки счетов раздела (`Invoice::crmMoney` — его области демо и города), с машиной и плательщиком. */
    public static function query(): Builder
    {
        return PayLink::query()->select('billing_pay_links.*')
            ->join('billing_invoices', 'billing_invoices.id', '=', 'billing_pay_links.invoice_id')
            ->whereIn('billing_pay_links.invoice_id', Invoice::crmMoney()->where('direction', 'issued')->select('billing_invoices.id'));
    }

    /** По открытой ссылке пытались и не вышло — красная точка у «Нам должны». */
    public static function tried(): bool
    {
        return self::query()->where('billing_pay_links.state', PayLinkState::Open->value)
            ->where(fn ($w) => $w->whereNotNull('billing_pay_links.error_at')->orWhereHas('attempts', fn ($a) => $a->where('status', 'canceled')))->exists();
    }

    /** «Сделка», «Гараж», «Вручную», у ручной, засчитанной в сделку, — «Вручную, в сделку». */
    public static function from(PayLink $l): string
    {
        $from = $l->origin();

        return $from === 'manual' && $l->invoice->credit_deal_id ? 'Вручную, в сделку' : self::FROM_LABELS[$from];
    }

    /**
     * Кто взял ссылку: у ручной — кто выставил счёт (ссылку к нему заводит система), у сделки — менеджер или сотрудник,
     * что выбрал плательщика или другую сумму; заведённая вместе со счётом сделки — «сама».
     */
    public static function creator(PayLink $l): ?User
    {
        return $l->origin() === 'manual' ? $l->invoice->creator : ($l->auto ? null : $l->creator);
    }

    /**
     * Кто взял ссылку — одним словом в «Создано» (09.10.2026, владелец: «либо имя, либо сделка; раз имя — значит
     * вручную»): у ручной — кто выставил, у сделки и гаража — откуда.
     */
    public static function who(PayLink $l): string
    {
        return match ($l->origin()) {
            'manual' => $l->invoice->creator?->shortName() ?? 'вручную',
            'deal' => 'сделка',
            default => 'гараж',
        };
    }

    /** ТС строкой без номера («Kia Rio») и номер отдельно — у таблицы свои столбцы. @return array{?string, ?int} */
    public static function car(PayLink $l): array
    {
        $o = $l->invoice->carOffer();

        return $o ? [$o->title(), OfferNumber::isPublic($o) ? $o->number : null] : [$l->invoice->car_title, null];
    }

    /**
     * Что со ссылкой одним-двумя словами — для строки списка (09.10.2026, владелец: «схалтурил»): длинная фраза
     * «ждём оплату, ещё не открывали» в каждой строке была стеной текста. Полная — `state()`, в карточке.
     *
     * @return array{string, string}
     */
    public static function short(PayLink $l): array
    {
        return match ($l->status()[0]) {
            'paid' => ['оплачено', 'text-open'],
            'canceled' => ['отменена', 'text-ink-dim'],
            'error' => ['оплата не открылась', 'text-danger'],
            'new' => ['не открывали', 'text-ink-muted'],
            'pending' => ['открыли', 'text-urgent'],
            'abandoned' => ['не оплатили', 'text-urgent'],
            'declined' => ['не прошла', 'text-danger'],
            default => ['ждём оплату', 'text-ink-muted'],
        };
    }

    /** Что со ссылкой — фраза и класс тона, как в шторке ссылки. */
    public static function state(PayLink $l): array
    {
        [$state, $tone] = $l->stateLine();

        return [$state, $l->state === PayLinkState::Canceled ? 'text-ink-dim' : ($l->state === PayLinkState::Paid ? 'text-open' : MoneyRows::tone($tone))];
    }
}
