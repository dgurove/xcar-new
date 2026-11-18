<?php

namespace App\Billing;

use App\Billing\Bank\Transaction;
use App\Offers\Deal;
use App\Support\Ago;
use App\Support\Money;

/**
 * Одно слово о деньгах под суммой в шапке блока «Продажи» (18.11.2026, владелец: «по несколько раз одно и то же» — сумма в
 * шапке одна, а что с ней — словом под ней, вместо строк поступлений, состояния счёта и «не отправлен» по отдельности):
 * «оплачено 31.10 по выписке», «пришло 500 000 из 885 000», «сообщили об оплате 12.11», «счёт не отправлен», «оплатить до 20.11»,
 * просрочка — красным. Счёта ещё нет — чего ждём («ждём покупателя»). Возвращает [слово, класс тона] или null.
 */
final class PayWord
{
    /** @return array{0: string, 1: string}|null */
    public static function of(?Invoice $i, ?Deal $deal = null): ?array
    {
        if (! $i) {
            return $deal ? self::gap($deal) : null;
        }
        if ($i->state === InvoiceState::Void) {
            return null;
        }
        if ($i->state === InvoiceState::Paid) {
            // Пришло выпиской — дата самой выписки (зачёт ложится в счёт позже, когда счёт встаёт).
            $bank = self::bankDay($i);

            return ['оплачено '.Ago::day($bank ?? $i->paid_at).($bank ? ' по выписке' : ''), 'text-open'];
        }
        if ($i->claimed() > 0) {
            return ['сообщили об оплате', 'text-ink-muted'];
        }
        if ($i->paidMoney() > 0) {
            return ['пришло '.Money::rub($i->paidMoney()).' из '.Money::rub((float) $i->total), $i->isOverdue() ? 'text-danger' : 'text-ink-muted'];
        }
        if ($i->deal_id && ! $i->isShownToManager()) {
            return ['счёт не отправлен', 'text-ink-muted'];
        }
        if ($i->isOverdue()) {
            return [mb_lcfirst($i->overdueLabel(true)), 'text-danger'];
        }

        return [$i->due_at ? 'оплатить до '.Ago::day($i->due_at) : $i->laterLabel(), 'text-ink-muted'];
    }

    /** Счёта нет — чего ждём (`Deal::invoiceGap`). */
    private static function gap(Deal $deal): ?array
    {
        if (! $deal->isActive()) {
            return null;
        }
        $word = match ($deal->invoiceGap()) {
            'buyer' => 'ждём покупателя',
            'passport' => 'не хватает данных покупателя',
            'price' => 'ждём цену продажи',
            'commission' => 'посчитать вознаграждение',
            'ros_requisites' => 'нет реквизитов Р.О.С.',
            default => null,
        };

        return $word ? [$word, 'text-ink-muted'] : null;
    }

    /** Деньги пришли выпиской Сбера (оплата счёта строкой выписки или зачётом разовой оплаты, что пришла выпиской) — последний день. */
    private static function bankDay(Invoice $i): ?\Carbon\CarbonInterface
    {
        $payments = $i->relationLoaded('payments') ? $i->payments : $i->payments()->get();
        $ids = $payments->whereNull('voided_at')->pluck('credit_invoice_id')->filter()->push($i->id)->all();
        $day = Transaction::whereIn('invoice_id', $ids)->max('booked_at');

        return $day ? \Illuminate\Support\Carbon::parse($day) : null;
    }
}
