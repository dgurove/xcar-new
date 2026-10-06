<?php

namespace App\Http\Admin;

use App\Billing\Acquiring\PayMethod;
use App\Billing\Invoice;
use App\Billing\PaymentSource;
use App\Users\User;
use Illuminate\Support\Collection;

/**
 * Фразы строк «Оплат» — одни и те же у таблицы (`admin/money/tab-table`) и строк (`admin/money/tab`): что за машина
 * или услуга, кто менеджер, как заплатили, что со ссылкой.
 */
final class MoneyRows
{
    /** Машина без запятой перед годом («Kia Rio 2019»): запятая отделяет её от плательщика; у разовой — услуга. */
    public static function what(Invoice $i): ?string
    {
        $offer = $i->deal?->offer ?? $i->offer ?? ($i->garageCar ?? $i->garagePayoutCar)?->offer;

        return $offer ? trim($offer->title().' '.$offer->year) : ($i->isService() ? $i->charges->first()?->title : null);
    }

    /** «Плательщик, машина». */
    public static function line(Invoice $i): string
    {
        return implode(', ', array_filter([$i->party->name, self::what($i)]));
    }

    /** Менеджер коротким именем; нет менеджера — плательщик. */
    public static function who(Invoice $i): string
    {
        return $i->manager()?->shortName() ?? $i->party->name;
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
        $day = $p->paid_at->translatedFormat('j M');

        return match ($p->source) {
            PaymentSource::Acquiring => 'оплатил '.$day.' по ссылке '.PayMethod::label($attempts->get($p->id)?->method),
            PaymentSource::Cash => 'оплатил '.$day.' наличными',
            PaymentSource::Offset => 'оставил себе вознаграждение '.$day,
            default => 'оплатил '.$day.' в банк'.($p->refNumber() ? ', п/п '.$p->refNumber() : ''),
        };
    }

    /** Выплата менеджеру: «выплатили 6 окт на счёт». */
    public static function paidOut(Invoice $i): ?string
    {
        $p = $i->payments->sortBy('paid_at')->last();

        return $p ? 'выплатили '.$p->paid_at->translatedFormat('j M').' '.($p->source === PaymentSource::Cash ? 'наличными' : 'на счёт') : null;
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

    /** Срок счёта словами и тоном: «до 8 окт» / «срок прошёл 5 окт». */
    public static function due(Invoice $i): ?array
    {
        return $i->due_at ? [($i->isOverdue() ? 'срок прошёл ' : 'до ').$i->due_at->translatedFormat('j M'), self::tone($i->light())] : null;
    }

    public static function tone(?string $tone): string
    {
        return match ($tone) { 'danger' => 'text-danger', 'urgent' => 'text-urgent', default => '' };
    }
}
