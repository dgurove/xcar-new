<?php

namespace App\Logistics;

use App\Billing\Invoice;
use App\Cars\Car;
use App\Cars\Category;
use App\Offers\Offer;
use App\Park\City;
use App\Park\Vehicle;
use App\Park\Yard;
use App\Search\Term;
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
    'agreed_at', 'picked_at', 'delivered_at', 'cancelled_at', 'cancel_reason', 'late_noticed_at'])]
class Transport extends Model
{
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

    /** Город машины — по предложению, у ТС парковки — её город. */
    public function city(): ?string
    {
        $lane = $this->lane();

        return $lane instanceof Offer ? $lane->settlement?->name : ($lane?->city_id ? City::name($lane->city_id) : null);
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
        $keeper = $offer?->keeper();
        if (! $keeper) {
            return null;
        }

        return ($offer->garageCar()->exists() ? 'В гараж ' : 'Менеджеру ').$keeper->shortName();
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
        $steps[] = ['key' => 'executor', 'title' => $assigned ? 'Вывозит '.$this->executorUser?->shortName() : ($this->executor === Executor::Logist ? 'У логиста '.$this->coordinator?->shortName() : 'Кто вывозит'),
            'status' => $status($assigned, ! $assigned), 'at' => $this->assigned_at,
            'hint' => implode(', ', array_filter([
                $this->executor === Executor::Logist && $this->coordinator && $assigned && $this->coordinator_id !== $this->executor_id ? 'через '.$this->coordinator->shortName() : null,
                $this->distance_km ? $this->distance_km.' км' : null,
                $this->cost ? Money::rub((int) $this->cost) : null,
                ! $assigned && $s === TransportState::New ? 'выбрать исполнителя' : null,
                ! $assigned && $s === TransportState::Logist ? 'логист назначает исполнителя и цену' : null,
            ])) ?: null];
        if ($this->isPickup()) {
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
        if ($by->isAdmin() || ! in_array($this->state, [TransportState::Assigned, TransportState::Agreed], true)) {
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

    /** Действует ли человек по задаче: исполнитель, её логист или админ. */
    public function isHandledBy(User $user): bool
    {
        return $user->isAdmin() || $this->isExecutedBy($user) || $this->isCoordinatedBy($user);
    }

    /**
     * Кто что видит: админ — всё; логист — свои (через него) и задачи своих исполнителей; остальные — назначенные им;
     * модератор — те, где ждут документы (Лиза отправляет СДЭКом).
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $q;
        }

        return $q->where(fn ($w) => $w->where('executor_id', $user->id)
            ->when($user->isLogist(), fn ($w) => $w->orWhere('coordinator_id', $user->id)
                ->orWhereIn('executor_id', User::where('logist_id', $user->id)->select('id')))
            ->when($user->sendsLogisticsDocs(), fn ($w) => $w->orWhere('docs_by_cdek', true)));
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

    /** Чей ход у человека: админу — без исполнителя, логисту — ждущие его, исполнителю — созвон, вывоз, доставка. */
    public function scopeTurnOf(Builder $q, User $user): Builder
    {
        if ($user->isAdmin()) {
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
        return $q->open()->where('docs_by_cdek', true)->whereNotNull('executor_id')->whereNull('docs_sent_at');
    }

    /** Поиск по машине: марка, модель, VIN, госномер, номер предложения; по адресам, владельцу и треку. */
    public function scopeSearch(Builder $q, string|Term $term): Builder
    {
        $t = Term::of($term);
        if ($t->blank()) {
            return $q;
        }
        $like = Term::like($t->raw);

        return $q->where(fn ($w) => $w->whereHas('offer', fn ($o) => $o->search($t))
            ->orWhereHas('vehicle', fn ($v) => $v->search($t))
            ->orWhere('from_address', 'ilike', $like)->orWhere('to_address', 'ilike', $like)->orWhere('docs_track', 'ilike', $like)
            ->orWhereHas('car', fn ($c) => $c->where('insured_name', 'ilike', $like)
                ->when($t->phone, fn ($c, $phone) => $c->orWhere('insured_phone', 'like', '%'.$phone.'%'))));
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
