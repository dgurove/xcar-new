<?php

namespace App\Offers;

use App\Billing\WorkDays;
use App\Live\Publisher;
use App\Live\Topics;
use App\Support\Ago;
use App\Support\Surface;
use App\Users\User;
use App\Vendors\DealFormat;
use App\Vendors\Vendor;
use App\Workflow\WaitsFor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Срок обязывающего предложения (19.10.2026, владелец: «очень не хватает в CRM и в логистике»): до когда поставщик держит
 * цену торгов (протокол CarXbid / SD-Assistance «Срок обязывающего предложения: 60 дней», Мигторг и БитАвто «Срок
 * действия предложения до …»; разбор — `DocumentFields::binding`). Поле продажи `offers.binding_until`.
 *
 * Паника — за `WORKDAYS` рабочих дня до срока и после него: у машины нет покупателя — ни живого подтверждения, ни сделки,
 * ни гаража (`panic`, `over`; владелец: «хотя бы подтверждение»), или сделка идёт, а поставщику ещё не заплатили (`deal`,
 * 19.10.2026: поставщик держит цену до срока, `Deal::supplierPaid`). Подтверждение есть, но не принято (`undecided`) —
 * только красная метка во вкладках подтверждений, без тревоги: решать админу. Одна дверь для CRM, логистики и звонка
 * (`BindingAlarm`); SQL-зеркала — `scope` / `panicSql`, менять вместе с `of`.
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

    /** Идёт сделка, а поставщику не заплачено — то же, что `Deal::supplierPaid`. */
    private const UNPAID_SQL = "exists (select 1 from deals d where d.offer_id = offers.id and d.state = 'active' and d.supplier_paid_at is null"
        .' and not (d.prepaid_at is not null and exists (select 1 from vendors v where v.id = offers.vendor_id and v.pay_before_pickup)))';

    /** Подтверждение есть, а сделки и гаража нет. */
    private const UNDECIDED_SQL = "(exists (select 1 from bids b where b.offer_id = offers.id and b.state = 'active')"
        ." and not exists (select 1 from deals d where d.offer_id = offers.id and d.state = 'active')"
        .' and not exists (select 1 from garage_cars g where g.offer_id = offers.id))';

    /**
     * Срок «горит», если он не позже этой границы: конец дня через два рабочих дня. Срок в четверг — паника со вторника
     * с утра, а не со вторника с того же часа.
     */
    public static function edge(): Carbon
    {
        return WorkDays::add(now(), self::WORKDAYS)->endOfDay();
    }

    /** @return 'set'|'panic'|'over'|'deal'|'undecided'|null */
    public static function of(Offer $offer): ?string
    {
        $until = $offer->binding_until;
        if (! $until) {
            return null;
        }
        $burning = $until->lte(self::edge());
        $live = in_array($offer->state, self::LIVE, true);
        // Списки считают горящее одним подзапросом (`select`) — без запросов на строку.
        if (array_key_exists('binding_panic', $offer->getAttributes())) {
            if ($offer->getAttribute('binding_panic')) {
                return ! $live || self::unpaidDeal($offer) ? 'deal' : ($until->isPast() ? 'over' : 'panic');
            }

            return $burning && $live && self::undecided($offer) ? 'undecided' : 'set';
        }
        if (! $burning) {
            return 'set';
        }
        if (self::unpaidDeal($offer)) {
            return 'deal';
        }
        if (! $live) {
            return 'set';
        }
        if (self::undecided($offer)) {
            return 'undecided';
        }

        return self::hasBuyer($offer) ? 'set' : ($until->isPast() ? 'over' : 'panic');
    }

    /** Горит всерьёз: нет покупателя или поставщику не заплачено — красная полоса, тревога, звонок. */
    public static function panics(Offer $offer): bool
    {
        return in_array(self::of($offer), ['panic', 'over', 'deal'], true);
    }

    /** Срок ещё что-то значит: продажа ищет покупателя или сделка не заплатила поставщику. */
    public static function matters(Offer $offer): bool
    {
        return $offer->binding_until !== null && self::applies($offer) && (in_array($offer->state, self::LIVE, true) || self::unpaidDeal($offer));
    }

    /**
     * Срок обязывающего — только у вендоров, что продают через нас по комиссии (Совкомбанк; 19.10.2026, владелец: «в Тинь
     * вообще не нужно, это совком информация»). У остальных поля нет, из документов и от вендора не встаёт, вписанное
     * стирается (`UpdateOffer`). Список — раз на запрос.
     */
    public static function applies(Offer $offer): bool
    {
        return $offer->vendor_id !== null && in_array((int) $offer->vendor_id, self::vendorIds(), true);
    }

    /** @return list<int> вендоры со сроком обязывающего */
    public static function vendorIds(): array
    {
        return once(fn () => Vendor::where('deal_format', DealFormat::Commission->value)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public static function hasBuyer(Offer $offer): bool
    {
        if ($offer->relationLoaded('activeBids') ? $offer->activeBids->isNotEmpty() : $offer->activeBids()->exists()) {
            return true;
        }

        return ($offer->relationLoaded('deal') ? $offer->deal !== null : $offer->deal()->exists())
            || ($offer->relationLoaded('garageCar') ? $offer->garageCar !== null : $offer->garageCar()->exists());
    }

    public static function unpaidDeal(Offer $offer): bool
    {
        $deal = $offer->relationLoaded('deal') ? $offer->deal : $offer->deal()->first();

        return $deal !== null && ! $deal->setRelation('offer', $offer)->supplierPaid();
    }

    private static function undecided(Offer $offer): bool
    {
        $bids = $offer->getAttribute('active_bids_count') ?? ($offer->relationLoaded('activeBids') ? $offer->activeBids->count() : $offer->activeBids()->count());

        return $bids > 0 && ! ($offer->relationLoaded('deal') ? $offer->deal : $offer->deal()->exists())
            && ! ($offer->relationLoaded('garageCar') ? $offer->garageCar : $offer->garageCar()->exists());
    }

    /**
     * То же в SQL у выборки предложений: `panic` — горит всерьёз (нет покупателя или поставщику не заплачено, и вышедшее
     * тоже), `over` — вышло без покупателя, `deal` — сделка не заплатила поставщику, `undecided` — подтверждение не принято,
     * `set` — срок есть, `none` — нет. `$table` — псевдоним таблицы предложений в подзапросе логистики.
     */
    public static function scope($query, string $level, string $table = 'offers'): void
    {
        $col = "{$table}.binding_until";
        $sql = fn (string $s) => str_replace('offers.', "{$table}.", $s);
        match ($level) {
            'none' => $query->whereNull($col),
            'set' => $query->whereNotNull($col),
            'panic' => $query->whereRaw(self::panicSql($table)),
            'over' => $query->whereRaw(self::panicSql($table))->whereRaw('not '.$sql(self::UNPAID_SQL))->where($col, '<', now()),
            'deal' => $query->whereNotNull($col)->where($col, '<=', self::edge())->whereRaw($sql(self::UNPAID_SQL)),
            'undecided' => $query->whereNotNull($col)->where($col, '<=', self::edge())->whereIn("{$table}.state", self::liveValues())->whereRaw($sql(self::UNDECIDED_SQL)),
        };
    }

    /** Горит всерьёз одним SQL — колонка списков, сортировка и подзапрос логистики. */
    public static function panicSql(string $table = 'offers'): string
    {
        $sql = fn (string $s) => str_replace('offers.', "{$table}.", $s);
        $states = implode(',', array_map(fn ($v) => "'{$v}'", self::liveValues()));

        return "({$table}.binding_until is not null and {$table}.binding_until <= '".self::edge()->toDateTimeString()."'"
            ." and (({$table}.state in ({$states}) and not ".$sql(self::BUYER_SQL).') or '.$sql(self::UNPAID_SQL).'))';
    }

    /** @return list<string> */
    private static function liveValues(): array
    {
        return array_map(fn (OfferState $s) => $s->value, self::LIVE);
    }

    /**
     * Срок обязывающего у задачи логистики (по последней продаже машины): `deal` — сделка не заплатила поставщику, `over` —
     * вышел без покупателя, `panic` — горит, `set` — есть, `empty` — нет (`none` у чипов занято под «без значения»).
     */
    public static function bucketSql(): string
    {
        return '(coalesce((select '.self::bucketCase('o')
            ." from offers o where o.car_id = transports.car_id and transports.origin <> 'park' order by o.id desc limit 1), 'empty'))";
    }

    /** Та же корзина у выборки предложений (`$table` — её имя или псевдоним): чип «Срок обязывающего» у вендора. */
    public static function bucketCase(string $table = 'offers'): string
    {
        return "(case when {$table}.binding_until is null then 'empty'"
            .' when '.self::panicSql($table).' and '.str_replace('offers.', "{$table}.", self::UNPAID_SQL)." then 'deal'"
            .' when '.self::panicSql($table)." and {$table}.binding_until < '".now()->toDateTimeString()."' then 'over'"
            .' when '.self::panicSql($table)." then 'panic' else 'set' end)";
    }

    /** Подписи корзин — одни на чипы логистики и вендора. */
    public const BUCKETS = ['panic' => 'Горит, нет покупателя', 'deal' => 'Горит, поставщику не заплачено', 'over' => 'Вышел, нет покупателя', 'set' => 'Есть срок', 'empty' => 'Нет срока'];

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
        // Числами «03.10» (20.10.2026, владелец), не «3 окт»: так срок пишут в протоколах торгов. Другой год — «03.10.25»,
        // время — только если не конец дня.
        $at = $until->copy()->timezone(config('app.timezone'));

        return $at->format($at->isCurrentYear() ? 'd.m' : 'd.m.y').($at->format('H:i') !== '23:59' ? ', '.$at->format('H:i') : '');
    }

    /** Фраза горящего срока: «осталось 1 д 5 ч» / «истёк 03.10». */
    public static function word(Offer $offer): ?string
    {
        return match (true) {
            ! in_array(self::of($offer), ['panic', 'over', 'deal', 'undecided'], true) => null,
            $offer->binding_until->isPast() => 'истёк '.self::date($offer->binding_until),
            default => Ago::left($offer->binding_until),
        };
    }

    /**
     * Срок от вендора, когда в документах его нет (19.10.2026, владелец): «Срок предложения, дней» в настройках вендора
     * (`vendors.binding_days`) от окончания торгов (`insurer_deadline_at`, иначе «ответить до», иначе сегодня), до конца дня.
     * Только в пустое поле; вендор без числа — null.
     */
    public static function fromVendor(Offer $offer): ?Carbon
    {
        if ($offer->binding_until || ! self::applies($offer)) {
            return null;
        }
        $days = (int) Vendor::whereKey($offer->vendor_id)->value('binding_days');
        if ($days < 1) {
            return null;
        }
        $from = $offer->insurer_deadline_at?->copy() ?? $offer->answer_by?->copy() ?? now();

        return $from->startOfDay()->addDays($days)->setTime(23, 59);
    }

    /**
     * Срок шага сделки (19.10.2026, владелец: «сроки шагов сделки не позже срока обязывающего»): пока поставщику не заплачено,
     * шаг, где ход наш или менеджера, кончается не позже срока обязывающего; шаг без своего срока получает его. Где ждём
     * поставщика — его срок не трогаем. Менеджер видит это обычной датой шага — без вендора и цены.
     */
    public static function stageDeadline(Offer $offer, ?Deal $deal, ?WaitsFor $waits, ?Carbon $own): ?Carbon
    {
        $until = $offer->binding_until;
        if (! $until || ! $deal?->isActive() || $waits === WaitsFor::Supplier || $deal->setRelation('offer', $offer)->supplierPaid()) {
            return $own;
        }

        return $own && $own->lt($until) ? $own : $until->copy();
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

    /**
     * Тревога в шапке (19.10.2026, владелец: «не убрать, пока не устранят причину, но чтобы интерфейс не перегораживали»):
     * красная капсула «🚨 N» в CRM и логистике — ведущим логистику (админы и модераторы); логисту и исполнителям — нет
     * (владелец: «это совком информация, видно только нам»).
     * В тревоге — горит и вышедшие за последние `ALARM_DAYS` дней (владелец: «только горящие и свежие»); давние — красным в
     * списках. Гаснет сама: покупатель, новый срок, машину убрали.
     */
    public const ALARM_DAYS = 7;

    /** Кому тревога на этом приложении. */
    public static function alarmsFor(?User $user, ?Surface $surface = null): bool
    {
        $surface ??= Surface::current();

        return $user !== null && match ($surface) {
            Surface::Crm => $user->leadsLogistics(),
            Surface::Logistics => $user->leadsLogistics(),
            default => false,
        };
    }

    /** Выборка тревог: горит или вышел недавно — нет покупателя или поставщику не заплачено. Только сотрудникам XCar. */
    public static function alarmQuery(User $user)
    {
        $q = Offer::query()->where('offers.binding_until', '>=', now()->subDays(self::ALARM_DAYS));
        self::scope($q, 'panic');
        // «Из таблицы Кати» — старые, что ещё актуализируют: в тревоге не шумят (19.10.2026, владелец).
        LegacyClaim::offList($q);

        return $user->leadsLogistics() ? $q : $q->whereRaw('false');
    }

    public static function alarmCount(?User $user): int
    {
        return self::alarmsFor($user) ? once(fn () => self::alarmQuery($user)->count()) : 0;
    }

    /**
     * Причина тревоги — подзаголовок группы на экране «Горит» (19.10.2026, начальник: «причину подзаголовками по группировке»),
     * по тому, что ближе к деньгам: сделка не заплатила поставщику, приём закрылся пустым, в продаже нет подтверждений, не
     * выставлено в продажу.
     */
    public const REASONS = [
        'unpaid' => 'Поставщику не заплачено',
        'closed' => 'Приём закрыт, подтверждений нет',
        'open' => 'В продаже, подтверждений нет',
        'draft' => 'Не выставлено в продажу',
    ];

    /** @return key-of<self::REASONS> */
    public static function reason(Offer $offer): string
    {
        return match (true) {
            self::unpaidDeal($offer) => 'unpaid',
            $offer->state === OfferState::Open => $offer->closed() ? 'closed' : 'open',
            default => 'draft',
        };
    }

    /** Менеджер идущей сделки — чип «Менеджер» экрана «Горит» (машина без покупателя — «Без менеджера»). */
    public const MANAGER_SQL = "(select d.buyer_id from deals d where d.offer_id = offers.id and d.state = 'active' order by d.id desc limit 1)";

    /** @return Collection<int, Offer> по сроку, ближние сверху */
    public static function alarms(User $user): Collection
    {
        return self::alarmQuery($user)->orderBy('offers.binding_until')->limit(100)->get();
    }

    /**
     * Тревога могла загореться или погаснуть (срок, подтверждение, сделка, гараж, состояние): шапки пересчитываются у всех,
     * кому она видна (`badges` → `/live/badges`). Логистика слушает только личные темы — каждому по его теме.
     */
    public static function touch(?Offer $offer): void
    {
        if (! $offer?->binding_until) {
            return;
        }
        $topics = User::logisticsLeads()->pluck('id')->map(fn (int $id) => Topics::user($id))->values()->all();
        app(Publisher::class)->badges($topics);
        // Экран «Горит» (`AlarmController`) — перечитывается у тех же людей: потушили — строка ушла.
        app(Publisher::class)->refreshSoon($topics, ['/alarms']);
    }
}
