<?php

namespace App\Offers;

use App\Billing\WorkDays;
use App\Support\Ago;
use Illuminate\Support\Carbon;

/**
 * Срок обязывающего предложения (19.10.2026, владелец: «очень не хватает в CRM и в логистике»): до когда поставщик держит
 * цену торгов (протокол CarXbid / SD-Assistance «Срок обязывающего предложения: 60 дней», Мигторг и БитАвто «Срок
 * действия предложения до …»; разбор — `DocumentFields::binding`). Поле продажи `offers.binding_until`.
 *
 * Паника — за `WORKDAYS` рабочих дня до срока и после него, пока у машины нет покупателя: ни живого подтверждения, ни
 * сделки, ни гаража (владелец: «хотя бы подтверждение»). Одна дверь для CRM, логистики и звонка (`BindingAlarm`);
 * SQL-зеркало — `scope`, менять вместе с `of`.
 */
final class Binding
{
    public const WORKDAYS = 2;

    /** Где покупателя ещё ищут: черновик, галерея, продажа. В сделке, гараже и архиве паники нет. */
    public const LIVE = [OfferState::Draft, OfferState::Gallery, OfferState::Open];

    /** Есть покупатель — то же, что `hasBuyer`. */
    private const BUYER_SQL = "(exists (select 1 from bids b where b.offer_id = offers.id and b.state = 'active')"
        ." or exists (select 1 from deals d where d.offer_id = offers.id and d.state = 'active')"
        .' or exists (select 1 from garage_cars g where g.offer_id = offers.id))';

    /**
     * Срок «горит», если он не позже этой границы: конец дня через два рабочих дня. Срок в четверг — паника со вторника
     * с утра, а не со вторника с того же часа.
     */
    public static function edge(): Carbon
    {
        return WorkDays::add(now(), self::WORKDAYS)->endOfDay();
    }

    /** @return 'set'|'panic'|'over'|null */
    public static function of(Offer $offer): ?string
    {
        $until = $offer->binding_until;
        if (! $until) {
            return null;
        }
        // Списки считают «нет покупателя» одним подзапросом (`select`) — без запроса на строку.
        $loose = array_key_exists('binding_panic', $offer->getAttributes()) ? ! $offer->getAttribute('binding_panic') : null;
        if (! in_array($offer->state, self::LIVE, true) || ($loose ?? self::hasBuyer($offer))) {
            return 'set';
        }

        return match (true) {
            $until->isPast() => 'over',
            $until->lte(self::edge()) => 'panic',
            default => 'set',
        };
    }

    public static function panics(Offer $offer): bool
    {
        return in_array(self::of($offer), ['panic', 'over'], true);
    }

    public static function hasBuyer(Offer $offer): bool
    {
        if ($offer->relationLoaded('activeBids') ? $offer->activeBids->isNotEmpty() : $offer->activeBids()->exists()) {
            return true;
        }

        return ($offer->relationLoaded('deal') ? $offer->deal !== null : $offer->deal()->exists())
            || ($offer->relationLoaded('garageCar') ? $offer->garageCar !== null : $offer->garageCar()->exists());
    }

    /**
     * То же в SQL у выборки предложений: `panic` — горит и вышло, `over` — только вышло, `set` — срок есть, `none` — нет.
     * `$table` — псевдоним таблицы предложений в подзапросе логистики.
     */
    public static function scope($query, string $level, string $table = 'offers'): void
    {
        $col = "{$table}.binding_until";
        match ($level) {
            'none' => $query->whereNull($col),
            'set' => $query->whereNotNull($col),
            default => $query->whereNotNull($col)
                ->whereIn("{$table}.state", array_map(fn (OfferState $s) => $s->value, self::LIVE))
                ->whereRaw('not '.str_replace('offers.id', "{$table}.id", self::BUYER_SQL))
                ->where($col, $level === 'over' ? '<' : '<=', $level === 'over' ? now() : self::edge()),
        };
    }

    /** Условие паники одним SQL — сортировка «горящие первыми» и подзапрос логистики. */
    public static function panicSql(string $table = 'offers'): string
    {
        $states = implode(',', array_map(fn (OfferState $s) => "'{$s->value}'", self::LIVE));

        return "({$table}.binding_until is not null and {$table}.state in ({$states}) and {$table}.binding_until <= '".self::edge()->toDateTimeString()."'"
            .' and not '.str_replace('offers.id', "{$table}.id", self::BUYER_SQL).')';
    }

    /**
     * Срок обязывающего у задачи логистики (по последней продаже машины): `over` — вышел без покупателя, `panic` — горит,
     * `set` — есть, `empty` — нет (`none` у чипов занято под «без значения»). Чип «Срок обязывающего» в «Вывозе».
     */
    public static function bucketSql(): string
    {
        return "coalesce((select case when o.binding_until is null then 'empty'"
            .' when '.self::panicSql('o')." and o.binding_until < '".now()->toDateTimeString()."' then 'over'"
            .' when '.self::panicSql('o')." then 'panic' else 'set' end"
            ." from offers o where o.car_id = transports.car_id and transports.origin <> 'park' order by o.id desc limit 1), 'empty')";
    }

    /** Срок обязывающего последней продажи машины задачи — сортировка «Вывоза». */
    public const UNTIL_SQL = "(select o.binding_until from offers o where o.car_id = transports.car_id and transports.origin <> 'park' order by o.id desc limit 1)";

    /**
     * Колонка `binding_panic` к выборке предложений: горит ли срок у этой строки (`panicSql`). `of` берёт её вместо
     * запросов на строку.
     */
    public static function select($query): void
    {
        if (! $query->getQuery()->columns) {
            $query->select('offers.*');
        }
        $query->selectRaw(self::panicSql().' as binding_panic');
    }

    /** «6 ноя, 16:00»; конец дня — без времени. */
    public static function date(Carbon $until): string
    {
        return Ago::day($until, time: $until->format('H:i') !== '23:59');
    }

    /** Фраза паники: «осталось 1 д 5 ч» / «истёк 3 окт». */
    public static function word(Offer $offer): ?string
    {
        return match (self::of($offer)) {
            'panic' => Ago::left($offer->binding_until),
            'over' => 'истёк '.self::date($offer->binding_until),
            default => null,
        };
    }

    /**
     * Позже этого «Приём подтверждений до» не ставится: поставщик дальше цену не держит. Срок уже прошёл — не ограничиваем
     * (паника и так горит).
     */
    public static function closeLimit(Offer $offer): ?Carbon
    {
        $until = $offer->binding_until;

        return $until && $until->isFuture() ? $until->copy() : null;
    }

    /** Срок приёма по умолчанию — не позже срока обязывающего. */
    public static function clampClose(Offer $offer, Carbon $at): Carbon
    {
        $limit = self::closeLimit($offer);

        return $limit && $at->gt($limit) ? $limit : $at;
    }

    /** Ошибка поля, если срок приёма позже срока обязывающего. */
    public static function closeError(Offer $offer, ?\DateTimeInterface $at): ?string
    {
        $limit = self::closeLimit($offer);

        return $limit && $at && Carbon::instance($at)->gt($limit) ? 'Приём не позже срока обязывающего предложения: '.self::date($limit) : null;
    }
}
