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
 * Приглушение — только у задач без исполнителя: ищем покупателя меньше трёх суток — строка приглушена (`waiting`), три и
 * больше — слово красным, строка в полный цвет. Меток и чипа «Срочно» нет (18.10.2026, владелец: «только мешает», слово и
 * так красное).
 */
final readonly class SaleState
{
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

    /** Кому везти — менеджер: держатель гаража, иначе покупатель идущей сделки, если он менеджер (`Choices::managerOf`). */
    public const MANAGER_SQL = "(select coalesce(
        (select g.manager_id from garage_cars g where g.offer_id = o.id order by g.id desc limit 1),
        (select d.buyer_id from deals d join users u on u.id = d.buyer_id where d.offer_id = o.id and d.state = 'active' and not d.is_demo
            and (u.roles @> '[\"manager\"]'::jsonb or u.roles @> '[\"super_buyer\"]'::jsonb) order by d.id desc limit 1))
        from offers o where o.car_id = transports.car_id and transports.origin <> 'park' order by o.id desc limit 1)";

    /** Вендор задачи: у продажи — её, у перевода парковки — вендор ТС. */
    public const VENDOR_SQL = "(case when transports.origin = 'park'
        then (select v.vendor_id from park_vehicles v where v.car_id = transports.car_id order by v.id desc limit 1)
        else (select o.vendor_id from offers o where o.car_id = transports.car_id order by o.id desc limit 1) end)";

    /** Столько полных суток без покупателя — пора вывозить. */
    public const DAYS = 3;

    private function __construct(
        public string $key,
        public string $word,
        /** Класс цвета слова: text-accent-text (покупатель есть), text-urgent (ждём оплаты), text-ink-muted. */
        public string $tone,
        public ?User $manager,
        public bool $waiting,
        public int $days,
    ) {}

    /** Подгрузка, без которой `of()` спрашивает базу на каждой строке (`offer.vendor.workflows` — для `Deal::pickupHold`). */
    public const WITH = ['offer.settlement', 'offer.vendor.workflows', 'offer.garageCar.manager', 'offer.deal.buyer', 'vehicle'];

    public static function of(Transport $t): self
    {
        $o = $t->forSale() ? $t->offer : null;
        // Полные сутки с поступления, а не календарные дни.
        $days = (int) ($t->created_at?->diffInDays(now()) ?? 0);
        $key = match (true) {
            ! $t->forSale() => 'park',
            ! $o => 'none',
            $o->garageCar !== null => 'garage',
            $o->deal !== null => 'deal',
            $o->state === OfferState::Open => 'open',
            in_array($o->state, [OfferState::Draft, OfferState::Gallery], true) => 'draft',
            default => 'off',
        };
        $manager = in_array($key, ['garage', 'deal'], true) ? Choices::managerOf($o) : null;
        $buyer = in_array($key, ['garage', 'deal'], true);
        $deciding = $t->state === TransportState::New;
        $hold = $key === 'deal' ? $o->deal->setRelation('offer', $o)->pickupHold() : null;
        // Сколько ждёт — с поступления заявки, а не с публикации (18.10.2026, владелец: «ищем покупателя 2д 14ч»; срок
        // приёма в строке логистике не нужен).
        $since = $t->created_at ? ' '.Ago::since($t->created_at) : '';
        $word = match ($key) {
            'park' => 'перевод парковки',
            'garage' => 'гараж',
            'deal' => $hold ? 'сделка, '.$hold : 'сделка',
            'open' => 'ищем покупателя'.$since,
            'none', 'draft' => 'не опубликовано'.$since,
            default => 'снята с продажи',
        };

        // Три дня без покупателя — слово красным и строка в полный цвет: «пизда рулю, надо срочно что-то делать»
        // (18.10.2026, владелец); меньше — ещё ищем, строка без исполнителя приглушена.
        $late = in_array($key, ['open', 'draft', 'none'], true) && $days >= self::DAYS;

        return new self(
            key: $key,
            word: $word,
            tone: match (true) {
                $late => 'text-danger',
                $hold !== null => 'text-urgent',
                $buyer => 'text-accent-text',
                default => 'text-ink-muted',
            },
            manager: $manager,
            waiting: $deciding && ! $buyer && $key !== 'park' && ! $late,
            days: $days,
        );
    }
}
