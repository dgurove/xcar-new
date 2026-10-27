<?php

namespace App\Park;

use App\Billing\Charge;
use App\Billing\Invoice;
use App\Cars\Settlement;
use App\Mail\Account;
use App\Mail\Candidate;
use App\Mail\Scope;
use App\Users\User;
use App\Vendors\Contact;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Город парковки — граница того, что видит управляющий (владелец 09.10.2026: у Питера свой управляющий, он видит только
 * свою парковку и письма питерской Альфы, московские — только Москву, весь парк — администраторы парковки).
 *
 * Город ТС хранится (`park_vehicles.city_id`, `Vehicle::saving`): город её парковки, без парковки — парковки вендора по
 * умолчанию (`vendors.home_yard_id`), иначе основной — Москва. Ветка и цепочка «Из писем» — город своей ТС, без неё —
 * вендора; счёт — ТС или вендора-плательщика реестра. Человек с городом (`users.park_city_id`) видит только его: области
 * `SCOPE` у ТС, заявок, веток, цепочек и счетов включаются им самим (`viewing`); очереди, расписание и разбор писем
 * идут без вошедшего — для них города нет.
 */
final class City
{
    public const SCOPE = 'park-city';

    private static ?int $moscow = null;

    /** Глубина `unscoped`: внутри — служебная работа (приём почты, привязка писем, уникальность), город не режет. */
    private static int $paused = 0;

    /** @var list<int>|null ящики парковки — на процесс, их набор меняется раз в год */
    private static ?array $parkAccounts = null;

    /**
     * Служебная работа в запросе управляющего с городом — без областей: приём почты («Проверить почту»), разбор письма,
     * цепочки, привязка веток и предложений, проверки уникальности должны видеть весь парк, иначе плодят вторые ветки
     * и цепочки или привязывают не всё. Город — граница просмотра, а не данных.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function unscoped(callable $fn): mixed
    {
        self::$paused++;
        try {
            return $fn();
        } finally {
            self::$paused--;
        }
    }

    /** Основной город: ТС и письма без парковки и без вендора с парковкой — его. */
    public static function main(): ?int
    {
        return self::$moscow ??= Settlement::where('name', 'Москва')->where('region_id', 77)->orderBy('id')->value('id');
    }

    /** Город, которым ограничен человек; null — весь парк. Администратору парковки — всегда весь. */
    public static function of(?User $user): ?int
    {
        return $user && $user->park_city_id && ! $user->isParkAdmin() ? (int) $user->park_city_id : null;
    }

    /** Город вошедшего — только уже поднятого из сессии (`hasUser`): проверка не должна запускать вход. */
    public static function viewing(): ?int
    {
        return self::$paused === 0 && Auth::hasUser() ? self::of(Auth::user()) : null;
    }

    /** Город ТС по её парковке и вендору — то, что пишет `Vehicle::saving`. */
    public static function forVehicle(Vehicle $v): ?int
    {
        if ($v->yard_id && ($city = Yard::withoutGlobalScope(self::SCOPE)->whereKey($v->yard_id)->value('settlement_id'))) {
            return (int) $city;
        }
        if (! $v->yard_id && $v->city_id && ! $v->isDirty('vendor_id')) {
            return (int) $v->city_id;
        }

        return self::vendorCity($v->vendor_id);
    }

    /**
     * Город писем вендора без своей парковки у отправителя (27.10.2026, владелец: «если не уверены, к какому городу заявка,
     * — пусть и там и там»): парковка вендора по умолчанию; без неё — город, где его ТС стояли; стояли в разных (ВСК —
     * Москва и Питер) — null: не уверены, ТС видна управляющим всех городов, пока её не примут на парковку. Вендор новый —
     * основной город.
     */
    public static function vendorCity(?int $vendorId): ?int
    {
        if (! $vendorId) {
            return self::main();
        }
        if ($home = self::ofVendor($vendorId)) {
            return $home;
        }
        $cities = self::vendorCities()[$vendorId] ?? [];

        return match (count($cities)) {
            0 => self::main(),
            1 => $cities[0],
            default => null,
        };
    }

    /**
     * Город заявки из письма (27.10.2026, владелец: «один и тот же отправитель может прислать и для Питера, и для Москвы»):
     * парковка, вписанная у контакта отправителя руками, — её город; иначе история отправителя — ТС его заявок стояли в
     * одном городе — он, в разных — null (не уверены, видна всем); писем от него ещё не было — город вендора (`vendorCity`).
     */
    public static function senderCity(?string $email, ?int $vendorId): ?int
    {
        if ($yard = Contact::yardFor($email)) {
            return $yard->settlement_id ? (int) $yard->settlement_id : self::vendorCity($vendorId);
        }
        $email = mb_strtolower(trim((string) $email));
        $cities = $email === '' ? [] : DB::table('park_vehicles')->join('park_requests', 'park_requests.vehicle_id', '=', 'park_vehicles.id')
            ->join('mail_messages', 'mail_messages.id', '=', 'park_requests.letter_id')->whereRaw('lower(mail_messages.from_email) = ?', [$email])
            ->whereNotNull('park_vehicles.yard_id')->whereNotNull('park_vehicles.city_id')->distinct()->pluck('park_vehicles.city_id')->map(fn ($c) => (int) $c)->all();

        return match (count($cities)) {
            0 => self::vendorCity($vendorId),
            1 => $cities[0],
            default => null,
        };
    }

    /** @return array<int, list<int>> вендор → города, где его ТС стояли на парковке */
    private static function vendorCities(): array
    {
        return once(fn () => DB::table('park_vehicles')->whereNotNull('yard_id')->whereNotNull('vendor_id')->whereNotNull('city_id')
            ->distinct()->get(['vendor_id', 'city_id'])->groupBy('vendor_id')->map(fn ($g) => $g->pluck('city_id')->map(fn ($c) => (int) $c)->all())->all());
    }

    /** Город вендора — его парковки по умолчанию; null — нет такой (письма такого вендора — основного города). */
    public static function ofVendor(?int $vendorId): ?int
    {
        if (! $vendorId) {
            return null;
        }
        $city = DB::table('vendors')->join('park_yards', 'park_yards.id', '=', 'vendors.home_yard_id')->where('vendors.id', $vendorId)->value('park_yards.settlement_id');

        return $city ? (int) $city : null;
    }

    /** Город цепочки «Из писем»: её ТС, иначе вендора (`vendorCity`; null — не уверены, видна всем городам). */
    public static function ofCandidate(Candidate $c): ?int
    {
        $vehicle = $c->vehicle_id ? Vehicle::withoutGlobalScope(self::SCOPE)->whereKey($c->vehicle_id)->value('city_id') : null;

        return $vehicle ? (int) $vehicle : self::vendorCity($c->vendor_id);
    }

    /** Город счёта: его ТС, иначе вендора-плательщика реестра, иначе основной. */
    public static function ofInvoice(Invoice $i): ?int
    {
        $vehicle = $i->vehicle_id ? Vehicle::withoutGlobalScope(self::SCOPE)->whereKey($i->vehicle_id)->value('city_id') : null;

        return $vehicle ? (int) $vehicle : (self::ofVendor(Vendor::where('party_id', $i->party_id)->value('id')) ?? self::main());
    }

    /**
     * Вендоры, чьи реестры счетов — города: с парковкой по умолчанию в нём, для основного — и без неё. Реестр не ТС: «не
     * уверены» к нему не относится, московский реестр ВСК питерскому управляющему не нужен.
     */
    public static function homeVendorIds(int $city): \Illuminate\Database\Query\Builder
    {
        return DB::table('vendors')->leftJoin('park_yards', 'park_yards.id', '=', 'vendors.home_yard_id')
            ->where(fn ($w) => $w->where('park_yards.settlement_id', $city)
                ->when($city === self::main(), fn ($m) => $m->orWhereNull('vendors.home_yard_id')))
            ->select('vendors.id');
    }

    /** Вендоры, чьи письма относятся к городу (`vendorCity`): его город или не уверены — тогда ко всем. @return list<int> */
    public static function vendorIds(int $city): array
    {
        return once(fn () => DB::table('vendors')->pluck('id')->filter(fn ($id) => in_array(self::vendorCity((int) $id), [$city, null], true))->values()->all());
    }

    /** @return list<int> парковки, которые человек видит: его города или все */
    public static function yardIdsOf(?User $user): array
    {
        $city = self::of($user);

        return $city ? self::yardIds($city) : Yard::withoutGlobalScope(self::SCOPE)->pluck('id')->all();
    }

    /** @return list<int> парковки города */
    public static function yardIds(int $city): array
    {
        return Yard::withoutGlobalScope(self::SCOPE)->where('settlement_id', $city)->pluck('id')->all();
    }

    /**
     * Куда перевести ТС из её города: парковки других городов и «город, парковка не известна» (`Actions\Transfer`).
     * Пусто — город один. @return array<string, string>
     */
    public static function transferTargets(Vehicle $vehicle): array
    {
        $out = [];
        foreach (self::options() as $city => $name) {
            if ($city === (int) $vehicle->city_id) {
                continue;
            }
            foreach (Yard::withoutGlobalScope(self::SCOPE)->where('settlement_id', $city)->where('is_active', true)->orderBy('name')->get() as $yard) {
                $out['y:'.$yard->id] = $name.', '.$yard->name;
            }
            $out['c:'.$city] = $name.', парковка не известна';
        }

        return $out;
    }

    /** Подпись города для людей: «Москва», «Санкт-Петербург». */
    public static function name(?int $city): ?string
    {
        return $city ? once(fn () => Settlement::whereKey($city)->value('name')) : null;
    }

    /** Города, где есть парковки, — выбор в ссылке и у человека. @return array<int, string> */
    public static function options(): array
    {
        return once(fn () => Settlement::whereIn('id', Yard::withoutGlobalScope(self::SCOPE)->whereNotNull('settlement_id')->select('settlement_id'))->orderBy('name')->pluck('name', 'id')->all());
    }

    // Области по городу: каждая вешается моделью в `booted` и молчит без города у вошедшего.

    /** ТС города — и ТС без города (не уверены, `vendorCity`): её видят все, пока не примут на парковку. */
    public static function vehicles(Builder $q, int $city): void
    {
        $column = $q->getModel()->qualifyColumn('city_id');
        $q->where(fn ($w) => $w->where($column, $city)->orWhereNull($column));
    }

    public static function requests(Builder $q, int $city): void
    {
        $q->whereIn($q->getModel()->qualifyColumn('vehicle_id'), Vehicle::query()->select('id'));
    }

    /** Ветки парковки — своей ТС, своей цепочки или, без них, вендора города; ветки CRM этой областью не трогаются. */
    public static function threads(Builder $q, int $city): void
    {
        $t = $q->getModel();
        $park = self::$parkAccounts ??= Account::where('scope', Scope::Park)->pluck('id')->all();
        $q->where(fn ($w) => $w->whereNotIn($t->qualifyColumn('account_id'), $park ?: [0])
            ->orWhereIn($t->qualifyColumn('vehicle_id'), Vehicle::query()->select('id'))
            ->orWhere(fn ($n) => $n->whereNull($t->qualifyColumn('vehicle_id'))->where(fn ($c) => $c
                ->whereIn($t->qualifyColumn('candidate_id'), Candidate::query()->select('id'))
                ->orWhereIn($t->qualifyColumn('vendor_id'), self::vendorIds($city))
                ->when($city === self::main(), fn ($m) => $m->orWhereNull($t->qualifyColumn('vendor_id'))))));
    }

    /** Цепочки «Из писем» парковки — своей ТС, без неё — вендора города; цепочки CRM этой областью не трогаются. */
    public static function candidates(Builder $q, int $city): void
    {
        $c = $q->getModel();
        $q->where(fn ($w) => $w->where($c->qualifyColumn('scope'), '!=', Scope::Park->value)
            ->orWhereIn($c->qualifyColumn('vehicle_id'), Vehicle::query()->select('id'))
            ->orWhere(fn ($n) => $n->whereNull($c->qualifyColumn('vehicle_id'))->where(fn ($v) => $v
                ->whereIn($c->qualifyColumn('vendor_id'), self::vendorIds($city))
                ->when($city === self::main(), fn ($m) => $m->orWhereNull($c->qualifyColumn('vendor_id'))))));
    }

    /** Счета: своей ТС, реестр — вендора города, без ТС и вендора (покупатель, комитент) — по ТС строк счёта. */
    public static function invoices(Builder $q, int $city): void
    {
        $i = $q->getModel();
        $q->where(fn ($w) => $w->whereIn($i->qualifyColumn('vehicle_id'), Vehicle::query()->select('id'))
            ->orWhere(fn ($n) => $n->whereNull($i->qualifyColumn('vehicle_id'))->where(fn ($r) => $r
                ->whereIn($i->qualifyColumn('party_id'), Vendor::whereIn('id', self::homeVendorIds($city))->whereNotNull('party_id')->select('party_id'))
                ->orWhereIn($i->qualifyColumn('id'), Charge::whereIn('vehicle_id', Vehicle::query()->select('id'))->whereNotNull('invoice_id')->select('invoice_id')))));
    }
}
