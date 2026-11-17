<?php

namespace App\Http\Admin;

use App\Billing\Acquiring\PayMethod;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\PartyName;
use App\Billing\ServiceTitle;
use App\Billing\PaymentSource;
use App\Support\Ago;
use App\Users\User;
use Illuminate\Support\Collection;

/**
 * Фразы строк «Оплат» — одни и те же у таблицы (`admin/money/tab-table`) и строк (`admin/money/tab`): что за машина
 * или услуга, кто менеджер, как заплатили, что со ссылкой.
 */
final class MoneyRows
{
    /** Машина без запятой перед годом («Kia Rio 2019»): запятая отделяет её от плательщика; у разовой — машина словами или услуга. */
    public static function what(Invoice $i): ?string
    {
        $offer = $i->deal?->offer ?? $i->offer ?? ($i->garageCar ?? $i->garagePayoutCar)?->offer;

        // Ручная оплата с машиной словами («Lada Vesta» без предложения) — она, а не название услуги; услуга по умолчанию
        // («Консультационные услуги по подбору ТС», `ServiceTitle::DEFAULT`) — не ТС: в столбце ТС она раздувала ширину
        // и ничего не говорила (17.11.2026), строка таблицы пишет «не указано».
        $title = $i->isService() ? $i->charges->first()?->title : null;

        return $offer ? trim($offer->title().' '.$offer->year) : ($i->car_title ?: ($title !== ServiceTitle::DEFAULT ? $title : null));
    }

    /** «Плательщик, машина». */
    public static function line(Invoice $i): string
    {
        return implode(', ', array_filter([PartyName::short($i->party->name), self::what($i)]));
    }

    /** Менеджер коротким именем; нет менеджера — плательщик или получатель (логист) коротким видом (`PartyName`). */
    public static function who(Invoice $i): string
    {
        return $i->manager()?->shortName() ?? PartyName::short($i->party->name);
    }

    /** За что выплата — строкой в группе получателя «Мы должны»: «Вознаграждение», «Вывоз» (в CRM вывоз, не «эвакуация»). */
    public static function payoutKind(Invoice $i): string
    {
        return match ($i->kind) {
            ChargeKind::AgentFee => 'Вознаграждение',
            ChargeKind::Tow => 'Вывоз',
            default => $i->kind->label(),
        };
    }

    /** Платит покупатель менеджера, а не он сам — менеджер, иначе null. */
    public static function via(Invoice $i): ?User
    {
        $m = $i->manager();

        return $m && $m->party_id !== $i->party_id ? $m : null;
    }

    /** Как заплатили — по последней оплате: «оплатил 4 окт по ссылке через СБП», «оставил себе вознаграждение 6 окт». */
    public static function how(Invoice $i, Collection $attempts): ?string
    {
        $p = $i->payments->sortBy('paid_at')->last();
        if (! $p) {
            return null;
        }
        $day = Ago::day($p->paid_at);

        return match ($p->source) {
            PaymentSource::Acquiring => 'оплатил '.$day.' по ссылке '.PayMethod::label($attempts->get($p->id)?->method),
            PaymentSource::Cash => 'оплатил '.$day.' наличными',
            PaymentSource::Offset => 'оставил себе вознаграждение '.$day,
            PaymentSource::Credit => 'засчитана оплата по ссылке '.$day,
            default => 'оплатил '.$day.' в банк'.($p->refNumber() ? ', п/п '.$p->refNumber() : ''),
        };
    }

    /** Выплата менеджеру: «выплатили 6 окт на счёт». */
    public static function paidOut(Invoice $i): ?string
    {
        $p = $i->payments->sortBy('paid_at')->last();

        return $p ? 'выплатили '.Ago::day($p->paid_at).' '.($p->source === PaymentSource::Cash ? 'наличными' : 'на счёт') : null;
    }

    /** Что со ссылкой: [фраза, класс тона]; пытались и не вышло — словами ЮKassa, не трогали — «ещё не открывали». */
    public static function link(Invoice $i): ?array
    {
        $l = $i->openLink();
        if (! $l) {
            return null;
        }
        [$state, $tone] = $l->error_at || $l->attempts->isNotEmpty() ? $l->stateLine() : ['ссылку ещё не открывали', null];

        return [$state, self::tone($tone)];
    }

    /**
     * Что со ссылкой счёта — хвостом статуса строки «Нам должны» (17.11.2026: вкладки ссылок больше нет, её слово переехало
     * в строку счёта): [слово, тон] или null — ссылки нет или по ней всё ровно.
     */
    public static function linkWord(Invoice $i): ?array
    {
        $l = $i->openLink();

        return $l ? match ($l->status()[0]) {
            'new' => ['ссылку не открывали', 'text-ink-muted'],
            'pending' => ['ссылку открыли', 'text-urgent'],
            'abandoned' => ['по ссылке не оплатили', 'text-urgent'],
            'declined' => ['по ссылке не прошла', 'text-danger'],
            'error' => ['ссылка не открылась', 'text-danger'],
            default => null,
        } : null;
    }

    /** Срок счёта словами и тоном: «до 8 окт» / «срок прошёл 5 окт». */
    public static function due(Invoice $i): ?array
    {
        return $i->due_at ? [($i->isOverdue() ? 'срок прошёл ' : 'до ').Ago::day($i->due_at), self::tone($i->light())] : null;
    }

    public static function tone(?string $tone): string
    {
        return match ($tone) {
            'danger' => 'text-danger', 'urgent' => 'text-urgent', default => ''
        };
    }
}
