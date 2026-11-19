<?php

namespace App\Billing;

use App\Support\Ago;
use App\Support\Money;

/**
 * Слово справа в подписи блока «Счёт» (19.11.2026, владелец: «над строкой со счётом подпись „Счёт“ и справа „Отправлен“ зелёным
 * или „Не отправлен“ красным», как «Покупатель — данные есть»). Пара [слово, тон]: `ok` — зелёным, `bad` — красным (наш ход или
 * чего-то не хватает), `due` — оранжевым (ждём оплату со сроком), `muted` — серым (ждём). Кусок вида — `x-deal.doc-cap`.
 */
final class DocWord
{
    /** Сотруднику в CRM: отправлен ли, напомнили ли, пришли ли деньги. */
    public static function staff(Invoice $i): array
    {
        $sent = $i->shown_at ? 'отправлен '.Ago::day($i->shown_at) : null;

        return match (true) {
            $i->state === InvoiceState::Paid => ['оплачен '.Ago::day($i->paid_at ?? $i->updated_at), 'ok'],
            ! $i->isShownToManager() => ['не отправлен', 'bad'],
            $i->claimed() > 0 => ['ждём подтверждения', 'muted'],
            $i->remaining() < $i->total => ['осталось '.Money::rub($i->remaining()), 'due'],
            $i->nudge_at !== null => [trim(($sent ? $sent.', ' : '').'напомним '.Ago::day($i->nudge_at)), 'ok'],
            $i->nudged_at !== null => [trim(($sent ? $sent.', ' : '').'напомнили '.Ago::day($i->nudged_at)), 'ok'],
            default => [$sent ?? 'отправлен', 'ok'],
        };
    }

    /** Менеджеру на xcar.ru: счёта нет — в обработке или нужны его данные; есть — к оплате, ждём подтверждения, оплачен. */
    public static function manager(?Invoice $i, bool $dataReady): array
    {
        if (! $i) {
            return $dataReady ? ['в обработке', 'muted'] : ['нужны данные', 'bad'];
        }

        return match (true) {
            $i->state === InvoiceState::Paid => ['оплачен '.Ago::day($i->paid_at ?? $i->updated_at), 'ok'],
            $i->claimed() > 0 && round($i->remaining() - $i->claimed(), 2) <= 0 => ['ждём подтверждения', 'muted'],
            $i->remaining() < $i->total => ['осталось '.Money::rub($i->remaining()), 'due'],
            $i->due_at !== null => ['к оплате до '.$i->due_at->format('d.m'), 'due'],
            default => ['к оплате', 'due'],
        };
    }
}
