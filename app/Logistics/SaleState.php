<?php

namespace App\Logistics;

use App\Offers\OfferState;
use App\Support\Ago;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Где продажа машины — для того, кто решает, кому отдать вывоз (18.10.2026, владелец: «сразу видеть, что эти только
 * пришли и для них ищут покупателя, у этих покупатель есть — вот менеджер, кому везти, а эти три дня без покупателя —
 * срочно вывозить»). Одна дверь для строки «Вывоза», карточки задачи, чипа «Продажа» и сортировки; SQL-зеркала
 * (`KEY_SQL` …) — для чипов и порядка, ветки в том же порядке, что `of()`.
 *
 * Срочность и приглушение — только у задач без исполнителя: после назначения решение принято, подсвечивать нечего.
 * Срочно — покупателя нет, а с поступления машины (`transports.created_at`, письмо страховой) прошло `DAYS` дней;
 * ещё есть время и ищем покупателя — строка приглушена (`waiting`).
 */
final readonly class SaleState
{
    /** Дней без покупателя, после которых вывозить срочно (решение владельца 18.10.2026). */
    public const DAYS = 3;

    /** Ключ продажи: park (перевод парковки), none (продажи нет), garage, deal, open, draft, off (снята). Демо-сделки не в счёт — их прячет `HidesDemo`. */
    public const KEY_SQL = "(case when transports.origin = 'park' then 'park' else coalesce((select case
        when exists (select 1 from garage_cars g where g.offer_id = o.id) then 'garage'
        when exists (select 1 from deals d where d.offer_id = o.id and d.state = 'active' and not d.is_demo) then 'deal'
        when o.state = 'open' then 'open'
        when o.state in ('draft', 'gallery') then 'draft'
        else 'off' end from offers o where o.car_id = transports.car_id order by o.id desc limit 1), 'none') end)";

    /** Чип «Продажа» — корзины без пересечений: покупатель есть (сделка, гараж) · в продаже · не в продаже · снята · перевод. */
    public const BUCKET_SQL = '(case '.self::KEY_SQL." when 'deal' then 'buyer' when 'garage' then 'buyer' when 'none' then 'draft' else ".self::KEY_SQL.' end)';

    /** Порядок «Продажа»: покупатель есть — выше, дальше в продаже, не в продаже, снята, перевод. */
    public const RANK_SQL = '(case '.self::KEY_SQL." when 'garage' then 4 when 'deal' then 4 when 'open' then 2 when 'draft' then 1 when 'none' then 1 when 'off' then 0 else -1 end)";

    /** Кому везти — менеджер: держатель гаража, иначе покупатель идущей сделки. */
    public const MANAGER_SQL = "(select coalesce(
        (select g.manager_id from garage_cars g where g.offer_id = o.id order by g.id desc limit 1),
        (select d.buyer_id from deals d where d.offer_id = o.id and d.state = 'active' and not d.is_demo order by d.id desc limit 1))
        from offers o where o.car_id = transports.car_id and transports.origin <> 'park' order by o.id desc limit 1)";

    /** Вендор задачи: у продажи — её, у перевода парковки — вендор ТС. */
    public const VENDOR_SQL = "(case when transports.origin = 'park'
        then (select v.vendor_id from park_vehicles v where v.car_id = transports.car_id order by v.id desc limit 1)
        else (select o.vendor_id from offers o where o.car_id = transports.car_id order by o.id desc limit 1) end)";

    private function __construct(
        public string $key,
        public string $word,
        /** Класс цвета слова: text-danger (срочно), text-accent-text (покупатель есть), text-urgent (ждём оплаты), text-ink-muted. */
        public string $tone,
        public ?User $manager,
        public bool $urgent,
        public bool $waiting,
        public int $days,
    ) {}

    /** Подгрузка, без которой `of()` спрашивает базу на каждой строке. */
    public const WITH = ['offer.settlement', 'offer.garageCar.manager', 'offer.deal.buyer', 'vehicle'];

    public static function of(Transport $t): self
    {
        $o = $t->forSale() ? $t->offer : null;
        $days = (int) ($t->created_at?->copy()->startOfDay()->diffInDays(now()->startOfDay()) ?? 0);
        $key = match (true) {
            ! $t->forSale() => 'park',
            ! $o => 'none',
            $o->garageCar !== null => 'garage',
            $o->deal !== null => 'deal',
            $o->state === OfferState::Open => 'open',
            in_array($o->state, [OfferState::Draft, OfferState::Gallery], true) => 'draft',
            default => 'off',
        };
        $manager = match ($key) {
            'garage' => $o->garageCar->manager,
            'deal' => $o->deal->buyer,
            default => null,
        };
        $buyer = in_array($key, ['garage', 'deal'], true);
        $deciding = $t->state === TransportState::New;
        $urgent = $deciding && ! $buyer && $key !== 'park' && $t->created_at !== null && $t->created_at->lte(now()->subDays(self::DAYS));
        $hold = $key === 'deal' ? $o->deal->setRelation('offer', $o)->pickupHold() : null;
        $word = match ($key) {
            'park' => 'перевод парковки',
            'none' => 'нет в продаже',
            'garage' => 'гараж',
            'deal' => $hold ? 'сделка, '.$hold : 'сделка',
            'open' => $o->bidsOpen() ? ($o->bids_close_at ? 'в продаже до '.Ago::day($o->bids_close_at, time: true) : 'в продаже') : 'приём закрыт',
            'draft' => match (true) {
                $o->slot_at !== null => 'выйдет '.Ago::day($o->slot_at, time: true),
                $o->isGallery() => 'в галерее',
                default => 'не в продаже',
            },
            default => 'снята с продажи',
        };

        return new self(
            key: $key,
            word: $urgent ? $word.', '.$days."\u{00A0}д" : $word,
            tone: match (true) {
                $urgent => 'text-danger',
                $hold !== null => 'text-urgent',
                $buyer => 'text-accent-text',
                default => 'text-ink-muted',
            },
            manager: $manager,
            urgent: $urgent,
            waiting: $deciding && ! $buyer && ! $urgent && $key !== 'park',
            days: $days,
        );
    }

    /** Срочно: без исполнителя, покупателя нет, поступила `DAYS` дней назад и раньше — то же правило, что `of()`. */
    public static function urgent(Builder $q): Builder
    {
        return $q->where('transports.state', TransportState::New)
            ->where('transports.created_at', '<=', now()->subDays(self::DAYS))
            ->whereRaw(self::KEY_SQL." in ('draft', 'open', 'off', 'none')");
    }
}
