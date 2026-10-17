<?php

namespace App\Logistics;

use App\Billing\Invoice;
use App\Cars\Car;
use App\Cars\Category;
use App\Cars\Region;
use App\Cars\Settlement;
use App\Offers\Offer;
use App\Park\City;
use App\Park\Vehicle;
use App\Park\Yard;
use App\Search\Term;
use App\Support\Ago;
use App\Support\Money;
use App\Support\Plural;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Вывоз машины — задача Logistics XCar (17.10.2026, владелец: «логистика — полноценный этап сделки»). Совкомбанк обязывает
 * нас вывозить каждую машину по его заявке, поэтому задача встаёт вместе с предложением из письма (`OpenPickup`); у
 * остальных вендоров — «Вывезти» в CRM, у гаража — сама. Кто вывозит, решает админ (`AssignExecutor`): логист (Татьяна,
 * регионы — она назначает своего исполнителя, вводит расстояние и цену), менеджер сделки или наши люди. Исполнитель
 * созванивается с владельцем («Созвонились»: дата, место, детали), без печати ждёт документы СДЭКом (трек вводит
 * модератор), «Забрал» — фото, ключи, куда везёт, «Привёз». CRM и xcar.ru читают ту же задачу: где машина
 * (`offers.car_place`), можно ли выставлять счёт Совкомбанка, шаг получения сделки.
 *
 * Перевод ТС между парковками (`TransportKind::Move`) — та же строка без созвона и документов. Машина — одна запись
 * (`Cars\Car`): кадры с вывоза лежат у неё (`stage = pickup`, скрыты с витрины, пока админ не откроет).
 */
#[Fillable(['car_id', 'origin', 'kind', 'executor', 'executor_id', 'coordinator_id', 'from_address', 'pickup_at',
    'pickup_place', 'call_note', 'call_attempts', 'docs_by_cdek', 'docs_track', 'docs_sent_at', 'docs_sent_by', 'docs_received_at', 'to_address',
    'destination', 'to_yard_id', 'pickup_note', 'cost', 'distance_km', 'state', 'invoice_id', 'garage_cost_id', 'created_by', 'assigned_at',
    'agreed_at', 'picked_at', 'delivered_at', 'cancelled_at', 'cancel_reason', 'late_noticed_at', 'region_id', 'nudged_at'])]
class Transport extends Model
{
    /** Шаг сменился — напоминание по нему снова возможно (`logistics:tick`). */
    private const STEP = ['state', 'executor_id', 'coordinator_id', 'agreed_at'];

    protected static function booted(): void
    {
        static::saving(function (self $t) {
            if (! $t->exists || $t->isDirty(['car_id', 'origin', 'from_address'])) {
                $t->region_id = $t->regionFor();
            }
            if ($t->exists && $t->isDirty(self::STEP) && ! $t->isDirty('nudged_at')) {
                $t->nudged_at = null;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'kind' => TransportKind::class,
            'executor' => Executor::class,
            'state' => TransportState::class,
            'destination' => Point::class,
            'cost' => 'decimal:2',
            'distance_km' => 'int',
            'docs_by_cdek' => 'bool',
            'call_attempts' => 'array',
            'pickup_at' => 'datetime',
            'docs_sent_at' => 'datetime',
            'docs_received_at' => 'datetime',
            'assigned_at' => 'datetime',
            'agreed_at' => 'datetime',
            'picked_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'nudged_at' => 'datetime',
        ];
    }

    /** Машина задачи. */
    public function car(): BelongsTo
    {
        return $this->belongsTo(Car::class);
    }

    /** Задачу завела продажа (вывоз CRM), а не парковка (перевод между парковками). */
    public function forSale(): bool
    {
        return $this->origin !== 'park';
    }

    public function isPickup(): bool
    {
        return $this->kind !== TransportKind::Move;
    }

    /** Продажа, что заказала вывоз: её шаг получения, «забирать рано», расход гаража. У перевода парковки — нет. */
    public function saleOffer(): ?Offer
    {
        return $this->forSale() ? $this->offer : null;
    }

    /** ТС парковки, что заказала перевод: её парковка в точке B и оплаты парковки. У вывоза продажи — нет. */
    public function parkVehicle(): ?Vehicle
    {
        return $this->forSale() ? null : $this->vehicle;
    }

    /** Через что задачу видно людям: та дорожка, что её заказала, иначе другая (название, ссылка). */
    public function lane(): Offer|Vehicle|null
    {
        return $this->forSale() ? ($this->offer ?? $this->vehicle) : ($this->vehicle ?? $this->offer);
    }

    /** Продажа машины в CRM. */
    public function offer(): HasOne
    {
        return $this->hasOne(Offer::class, 'car_id', 'car_id')->withoutGlobalScopes();
    }

    /** ТС парковки — любого города: логистике город управляющего (`Park\City`) не указ. */
    public function vehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class, 'car_id', 'car_id')->withoutGlobalScope(City::SCOPE);
    }

    public function executorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executor_id');
    }

    /** Логист, через которого идёт задача (ветка «Логист»): назначает своего исполнителя и цену. */
    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function docsSender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'docs_sent_by');
    }

    public function toYard(): BelongsTo
    {
        return $this->belongsTo(Yard::class, 'to_yard_id')->withoutGlobalScope(City::SCOPE);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Регион машины — чип «Регион» списка и подпись окна «Кто вывозит» (`regionFor` ставит при сохранении). */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * Регион по машине: у продажи — регион её города, иначе названный в адресе осмотра или вывоза; у ТС парковки — регион
     * её города. Правило по региону не решает, кто вывозит (решает админ), — только сужает список.
     */
    public function regionFor(): ?int
    {
        $lane = $this->lane();
        if ($lane instanceof Offer) {
            // Регион города — из базы по `settlement_id`: связь, загруженная до смены города, отдала бы прежний.
            $city = $lane->settlement_id ? Settlement::whereKey($lane->settlement_id)->value('region_id') : null;

            return $city ?? Region::inText($lane->inspection_address ?? $this->from_address)?->id;
        }
        $city = $lane?->city_id ? Settlement::whereKey($lane->city_id)->value('region_id') : null;

        return $city ?? Region::inText($this->from_address)?->id;
    }

    /**
     * Город или адрес дорожки поменялся — регион задач машины вслед: продажа (`Offer::saved`, город, адрес осмотра), ТС
     * парковки (`Vehicle::saved`, город). Дорожки задача читает заново из базы: сохранённое уже там.
     */
    public static function syncRegion(Offer|Vehicle $lane): void
    {
        self::open()->where('car_id', $lane->car_id)->get()
            ->each(fn (self $t) => ($region = $t->regionFor()) !== $t->region_id ? $t->forceFill(['region_id' => $region])->saveQuietly() : null);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Тип ТС машины — иконкой перед названием, как в таблицах CRM. */
    public function category(): ?Category
    {
        return $this->car?->category;
    }

    public function title(): string
    {
        return $this->lane()?->titleWithYear() ?? 'ТС';
    }

    /** Владелец — страхователь машины (одна запись `Cars\Car`: правят в CRM, письмо дописывает — здесь то же). */
    public function ownerName(): ?string
    {
        return $this->car?->insured_name;
    }

    public function ownerPhone(): ?string
    {
        return $this->car?->insured_phone;
    }

    /** Откуда забирать: у вывоза — адрес осмотра предложения (его правят в CRM), у перевода — свой адрес. */
    public function fromAddress(): ?string
    {
        return $this->from_address ?? $this->saleOffer()?->inspection_address;
    }

    /** Город машины — по предложению («Серпухов, 50» — с номером региона), у ТС парковки — её город. */
    public function city(): ?string
    {
        $lane = $this->lane();

        return $lane instanceof Offer ? $lane->settlement?->title() : ($lane?->city_id ? City::name($lane->city_id) : null);
    }

    /** Вендор задачи — логотип с номером убытка в таблице: у продажи — её, у перевода парковки — вендор ТС. */
    public function vendorId(): ?int
    {
        return $this->forSale() ? $this->offer?->vendor_id : $this->vehicle?->vendor_id;
    }

    /** Кто вывозит словом: «Иван С.»; у логиста без исполнителя — сам логист. */
    public function executorName(): ?string
    {
        return $this->executorUser?->shortName() ?? $this->coordinator?->shortName();
    }

    public function isOpen(): bool
    {
        return $this->state->isOpen();
    }

    /** Документы едут СДЭКом и ещё не дошли до исполнителя. */
    public function awaitsDocs(): bool
    {
        return $this->docs_by_cdek && $this->docs_received_at === null;
    }

    /** Дата вывоза прошла, а «Забрал» нет — красным у админа и исполнителя. */
    public function isLate(): bool
    {
        return $this->state === TransportState::Agreed && $this->pickup_at !== null && $this->pickup_at->isPast();
    }

    /** Сколько раз не дозвонились. */
    public function missedCalls(): int
    {
        return count($this->call_attempts ?? []);
    }

    /** Куда везёт словом: «В гараж Бородину», «К нам», «Краснопутиловская», адрес. */
    public function destinationLabel(): ?string
    {
        return match ($this->destination) {
            Point::Keeper => self::keeperLabel($this->saleOffer()) ?? Point::Keeper->label(),
            Point::Yard => $this->toYard?->name ?? $this->to_address,
            Point::Other => $this->to_address,
            Point::Ours => trim(Point::Ours->label().($this->to_address ? ', '.$this->to_address : '')),
            null => $this->to_address,
        };
    }

    /** Точка «к менеджеру»: гаражная — «В гараж Бородин Ф.», машина его покупателю — «Менеджеру Бородин Ф.». */
    public static function keeperLabel(?Offer $offer): ?string
    {
        $keeper = $offer ? Choices::managerOf($offer) : null;
        if (! $keeper) {
            return null;
        }
        $garage = $offer->relationLoaded('garageCar') ? $offer->garageCar !== null : $offer->garageCar()->exists();

        return ($garage ? 'В гараж ' : 'Менеджеру ').$keeper->shortName();
    }

    /**
     * Что дальше — словом для строки и бейджа: решать админу, ждём логиста, позвонить владельцу, ждём документы,
     * дата вывоза, везут, привёз.
     */
    public function next(): string
    {
        return match (true) {
            $this->state === TransportState::New => 'выбрать исполнителя',
            $this->state === TransportState::Logist => 'ждёт исполнителя логиста',
            $this->state === TransportState::Assigned && $this->executor === Executor::Manager => 'забирает сам',
            $this->state === TransportState::Assigned && $this->isPickup() => 'позвонить владельцу',
            $this->state === TransportState::Assigned => 'забрать',
            $this->state === TransportState::Agreed && $this->docs_by_cdek && $this->docs_sent_at === null => 'ждёт отправки документов',
            $this->state === TransportState::Agreed && $this->awaitsDocs() => 'документы в пути',
            $this->state === TransportState::Agreed && $this->pickup_at => 'вывоз '.$this->pickup_at->translatedFormat($this->pickup_at->format('H:i') === '00:00' ? 'j M' : 'j M, H:i'),
            $this->state === TransportState::Agreed => 'забрать',
            default => mb_strtolower($this->state->label()),
        };
    }

    /**
     * Ход задачи лесенкой — одной для логистики и карточки «Вывоз» в CRM: кто вывозит, созвон, документы (если СДЭКом),
     * вывоз, привёз. Шаг: заголовок, состояние (`done` | `current` | `later`), когда, подсказка.
     *
     * @return list<array{key: string, title: string, status: string, at: ?Carbon, hint: ?string}>
     */
    public function steps(): array
    {
        $s = $this->state;
        $cancelled = $s === TransportState::Cancelled;
        $status = fn (bool $done, bool $current) => $done ? 'done' : ($current && ! $cancelled ? 'current' : 'later');
        $steps = [];
        $assigned = $this->executor_id !== null;
        // «Уже вывезли» задним числом без исполнителя (`Backdate`) — шаг позади, кто вёз, не знаем.
        $unknown = ! $assigned && $s->picked() && $this->executor !== Executor::Logist;
        $steps[] = ['key' => 'executor', 'title' => $assigned ? 'Вывозит '.($this->executorUser?->shortName() ?? 'исполнитель') : ($this->executor === Executor::Logist ? 'У логиста'.($this->coordinator ? ' '.$this->coordinator->shortName() : '') : ($unknown ? 'Исполнитель не указан' : 'Кто вывозит')),
            'status' => $status($assigned || $s->picked(), ! $assigned && ! $s->picked()), 'at' => $this->assigned_at,
            'hint' => implode(', ', array_filter([
                $this->executor === Executor::Logist && $this->coordinator && $assigned && $this->coordinator_id !== $this->executor_id ? 'через '.$this->coordinator->shortName() : null,
                $this->distance_km ? $this->distance_km.' км' : null,
                $this->cost ? Money::rub((int) $this->cost) : null,
                ! $assigned && $s === TransportState::New ? 'выбрать исполнителя' : null,
                ! $assigned && $s === TransportState::Logist ? 'логист назначает исполнителя и цену' : null,
            ])) ?: null];
        // Менеджер забирает сам — созвона и документов у логистики нет: «Автомобиль забрал» он жмёт в своей сделке.
        if ($this->isPickup() && $this->executor !== Executor::Manager) {
            $agreed = $this->agreed_at !== null;
            // Забрали — созвон и документы уже позади, даже если их не отмечали (задачи до 17.10.2026, админ за исполнителя).
            $steps[] = ['key' => 'call', 'title' => $agreed || $s->picked() ? 'Созвонились' : 'Созвон с владельцем', 'status' => $status($agreed || $s->picked(), $assigned && ! $agreed),
                'at' => $this->agreed_at,
                'hint' => $agreed ? implode(', ', array_filter([$this->pickup_at ? 'вывоз '.$this->pickup_at->translatedFormat($this->pickup_at->format('H:i') === '00:00' ? 'j M' : 'j M, H:i') : null, $this->pickup_place]))
                    : ($this->missedCalls() ? 'не дозвонились '.$this->missedCalls().' '.Plural::of($this->missedCalls(), ['раз', 'раза', 'раз']) : null)];
            if ($this->docs_by_cdek) {
                $got = $this->docs_received_at !== null;
                $steps[] = ['key' => 'docs', 'title' => $got ? 'Документы получены' : ($this->docs_sent_at ? 'Документы в пути' : 'Документы СДЭКом'),
                    'status' => $status($got || $s->picked(), $assigned && ! $got), 'at' => $this->docs_received_at ?? $this->docs_sent_at,
                    'hint' => $this->docs_track ? 'трек '.$this->docs_track : ($assigned ? 'ждём отправки' : null)];
            }
        }
        $keys = $this->car?->keys_count;
        // Менеджер везёт к себе сам: его «Автомобиль забрал» и есть «привёз» (`SelfPickup`) — один шаг, без «Привёз».
        if ($this->executor === Executor::Manager) {
            $steps[0]['title'] = $assigned ? 'Забирает '.($this->executorUser?->shortName() ?? 'менеджер') : $steps[0]['title'];
            $steps[] = ['key' => 'picked', 'title' => $s->picked() ? 'Забрал' : 'Забрать', 'status' => $status($s->picked(), $s === TransportState::Assigned),
                'at' => $this->delivered_at ?? $this->picked_at, 'hint' => null];

            return $steps;
        }
        $steps[] = ['key' => 'picked', 'title' => $s->picked() ? 'Вывезена' : 'Забрать', 'status' => $status($s->picked(), in_array($s, [TransportState::Agreed], true) || ($s === TransportState::Assigned && ! $this->isPickup())),
            'at' => $this->picked_at,
            'hint' => $s->picked() ? implode(', ', array_filter([$keys === null ? null : $keys.' '.Plural::of($keys, ['ключ', 'ключа', 'ключей']), $this->destinationLabel()])) : null];
        $steps[] = ['key' => 'delivered', 'title' => 'Привёз', 'status' => $status($s === TransportState::Delivered, $s === TransportState::Moving), 'at' => $this->delivered_at,
            'hint' => $s === TransportState::Delivered || $s === TransportState::Moving ? $this->destinationLabel() : null];

        return $steps;
    }

    /** Кадры с вывоза — у машины (`stage = pickup`, свойство `transport`); `at` — «Забрал» или «Привёз». */
    public function photos(?string $at = null): Collection
    {
        if (! $this->car) {
            return collect();
        }

        return $this->car->getMedia('photos')
            ->filter(fn (Media $m) => (int) $m->getCustomProperty('transport') === $this->id && ($at === null || $m->getCustomProperty('at') === $at))
            ->values();
    }

    /**
     * Почему этот человек пока не может нажать «Забрал»: не созвонились, документы не дошли, у продажи рано (Альфа до
     * оплаты, Каркаде до согласия поставщика — `Deal::pickupHold`). Админ отмечает за исполнителя и мимо этого. null — можно.
     */
    public function pickupBlocker(User $by): ?string
    {
        if ($by->leadsLogistics() || ! in_array($this->state, [TransportState::Assigned, TransportState::Agreed], true)) {
            return null;
        }
        if ($this->isPickup() && $this->state === TransportState::Assigned) {
            return 'Сначала созвонитесь с владельцем';
        }
        if ($this->awaitsDocs()) {
            return $this->docs_sent_at ? 'Отметьте, что документы получены' : 'Ждём, когда отправим документы';
        }
        $hold = $this->saleOffer()?->deal?->pickupHold();

        return match ($hold) {
            null => null,
            'после оплаты' => 'Забирать после оплаты',
            default => 'Ждём согласия поставщика',
        };
    }

    /** Ведёт ли человек эту задачу как исполнитель. */
    public function isExecutedBy(User $user): bool
    {
        return $this->executor_id === $user->id;
    }

    /** Логист этой задачи — ведёт её, пока не назначит (и после: следит за своими исполнителями). */
    public function isCoordinatedBy(User $user): bool
    {
        return $this->coordinator_id === $user->id;
    }

    /** Действует ли человек по задаче: исполнитель, её логист или тот, кто ведёт логистику (админ, модератор). */
    public function isHandledBy(User $user): bool
    {
        return $user->leadsLogistics() || $this->isExecutedBy($user) || $this->isCoordinatedBy($user);
    }

    /**
     * Кто что видит: ведущие логистику (админ, модераторы — 18.10.2026) — всё; логист — свои (через него) и задачи своих
     * исполнителей; остальные — назначенные им.
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $q->inLogistics();
        if ($user->leadsLogistics()) {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('executor_id', $user->id)
            ->when($user->isLogist(), fn ($w) => $w->orWhere('coordinator_id', $user->id)
                ->orWhereIn('executor_id', User::where('logist_id', $user->id)->select('id'))));
    }

    /**
     * Задачи логистики — без тех, что забирает менеджер сделки сам (17.10.2026, владелец: «если менеджер сам забирает, в
     * логистике не надо отображать»): он отмечает «Автомобиль забрал» в своей сделке (`SelfPickup`), а попросит — задача
     * уходит «Без исполнителя» (`RequestPickup`).
     */
    public function scopeInLogistics(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('executor')->orWhere('executor', '!=', Executor::Manager->value));
    }

    /** Вкладка списка логистики: без исполнителя, у логиста, в работе, в пути, готово; иначе — все. */
    public function scopeStep(Builder $q, string $step): Builder
    {
        return match ($step) {
            'new' => $q->where('state', TransportState::New),
            'logist' => $q->where('state', TransportState::Logist),
            'work' => $q->whereIn('state', [TransportState::Assigned, TransportState::Agreed]),
            'moving' => $q->where('state', TransportState::Moving),
            'done' => $q->where('state', TransportState::Delivered),
            default => $q,
        };
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('state', TransportState::OPEN);
    }

    /** Чей ход у человека: ведущим — без исполнителя, логисту — ждущие его, исполнителю — созвон, вывоз, доставка. */
    public function scopeTurnOf(Builder $q, User $user): Builder
    {
        if ($user->leadsLogistics()) {
            return $q->where('state', TransportState::New);
        }

        return $q->where(fn ($w) => $w
            ->when($user->isLogist(), fn ($w) => $w->orWhere(fn ($l) => $l->where('state', TransportState::Logist)->where('coordinator_id', $user->id)))
            ->orWhere(fn ($e) => $e->where('executor_id', $user->id)->where(fn ($s) => $s
                ->whereIn('state', [TransportState::Assigned, TransportState::Moving])
                ->orWhere(fn ($a) => $a->where('state', TransportState::Agreed)
                    ->where(fn ($d) => $d->where('docs_by_cdek', false)->orWhereNotNull('docs_sent_at'))))));
    }

    /** Документы ждут отправки СДЭКом: исполнитель без печати назначен, трека нет. */
    public function scopeAwaitingDocs(Builder $q): Builder
    {
        return $q->open()->inLogistics()->where('docs_by_cdek', true)->whereNotNull('executor_id')->whereNull('docs_sent_at');
    }

    /**
     * Поиск по машине: марка, модель, VIN, госномер, номер предложения; по адресам, владельцу и треку. `crm` — ведущим
     * логистику ещё номер убытка и телефон (как поиск CRM); внешним исполнителям номера убытка нет.
     */
    public function scopeSearch(Builder $q, string|Term $term, bool $crm = false): Builder
    {
        $t = Term::of($term);
        if ($t->blank()) {
            return $q;
        }
        $like = Term::like($t->raw);

        return $q->where(fn ($w) => $w->whereHas('offer', fn ($o) => $crm ? $o->searchCrm($t) : $o->search($t))
            ->orWhereHas('vehicle', fn ($v) => $v->search($t))
            ->orWhere('from_address', 'ilike', $like)->orWhere('to_address', 'ilike', $like)->orWhere('docs_track', 'ilike', $like)
            ->orWhereHas('car', fn ($c) => $c->where('insured_name', 'ilike', $like)
                ->when($t->phone, fn ($c, $phone) => $c->orWhere('insured_phone', 'like', '%'.$phone.'%'))));
    }

    /** Дата строки в SQL — порядок «Когда» (то же, что `shownAt`). */
    public const SHOWN_SQL = "(case transports.state when 'delivered' then transports.delivered_at when 'moving' then transports.picked_at
        when 'cancelled' then transports.cancelled_at when 'agreed' then coalesce(transports.pickup_at, transports.agreed_at)
        else coalesce(transports.assigned_at, transports.created_at) end)";

    /** Дата строки словом: сегодня — время, иначе «9 окт, 14:05» (без времени, когда его нет — вывоз назначен днём). */
    public function shownLabel(): string
    {
        $at = $this->shownAt();

        return $at ? Ago::stamp($at, time: $at->format('H:i') !== '00:00') : '';
    }

    /** Дата строки: когда вывоз, иначе когда назначили, забрали, привезли. */
    public function shownAt(): ?Carbon
    {
        return match ($this->state) {
            TransportState::Delivered => $this->delivered_at,
            TransportState::Moving => $this->picked_at,
            TransportState::Cancelled => $this->cancelled_at,
            TransportState::Agreed => $this->pickup_at ?? $this->agreed_at,
            default => $this->assigned_at ?? $this->created_at,
        };
    }

    /**
     * Открытая задача дорожки — одна: вторую не заводим, пока эта не доставлена или не отменена. Вывоз продажи и перевод
     * парковки у одной машины — разные задачи: отмена сделки перевод парковки не трогает.
     */
    public static function openFor(Offer|Vehicle|Car $car): ?self
    {
        return self::open()->where('car_id', $car instanceof Car ? $car->id : $car->car_id)
            ->when(! $car instanceof Car, fn ($q) => $q->where('origin', $car instanceof Offer ? 'sale' : 'park'))
            ->latest('id')->first();
    }
}
