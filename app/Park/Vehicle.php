<?php

namespace App\Park;

use App\Billing\Cadence;
use App\Billing\Charge;
use App\Billing\Invoice;
use App\Billing\Party;
use App\Cars\Brand;
use App\Cars\CarModel;
use App\Cars\Category;
use App\Cars\DamageZone;
use App\Cars\Identity;
use App\Cars\Vin\Vin;
use App\Cars\Vin\VinDecoder;
use App\Mail\Extraction\Code;
use App\Mail\Extraction\Patterns;
use App\Mail\Template;
use App\Mail\Thread;
use App\Media\HasPhotos;
use App\Offers\Flag;
use App\Offers\Offer;
use App\Search\CarWords;
use App\Search\Term;
use App\Support\Phone;
use App\Support\Surface;
use App\Users\User;
use App\Vendors\DocRequirement;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable(['ref', 'vin', 'brand_id', 'model_id', 'year', 'plate', 'color', 'category', 'oversize', 'vendor_id', 'state', 'yard_id', 'accepted_at', 'released_at', 'damage_zones', 'damage_note', 'notes', 'offer_id',
    'contact_name', 'contact_phone', 'flags', 'docs_required', 'value', 'policy_no', 'city_id',
    'cancelled_at', 'cancel_reason', 'spot', 'mileage', 'fuel', 'idle_noticed_at',
    'owner_party_id', 'contract_kind', 'contract_no', 'contract_at', 'assigned_price', 'storage_rate', 'storage_rate_note', 'storage_billed_until', 'pts', 'sts',
    'sold_at', 'sold_message_id', 'buyer_free_until', 'pickup_name', 'pickup_phone', 'pickup_code', 'buyer_party_id', 'billing_cadence'])]
class Vehicle extends Model implements HasMedia
{
    use HasPhotos;

    protected $table = 'park_vehicles';

    /** ТС стирают («Заведена по ошибке»), предложение остаётся — его файлы уходят к нему. Раньше spatie: тот стирает всё. */
    protected static function booting(): void
    {
        static::deleting(fn (self $v) => $v->offer_id ? Sale::release($v) : null);
    }

    protected static function booted(): void
    {
        // Управляющий с городом видит только ТС своего города (`City`); город ТС — по парковке и вендору.
        static::addGlobalScope(City::SCOPE, fn (Builder $q) => ($city = City::viewing()) ? City::vehicles($q, $city) : null);
        static::saving(function (self $v) {
            if (! $v->city_id || $v->isDirty(['yard_id', 'vendor_id'])) {
                $v->city_id = City::forVehicle($v);
            }
        });
        // Тип ТС пуст, а марка есть — угадываем (12.10.2026: у 59 машин не было ставки только из-за пустого типа).
        static::saving(function (self $v) {
            if (! $v->category && $v->brand_id) {
                $v->category = $v->guessCategory();
            }
        });
        // Одна машина — одна запись: номер убытка или VIN другой ТС не встанет (`Identity`).
        static::saving(fn (self $v) => Identity::guardVehicle($v));
        // ТС вендора на парковке — вендор становится её вендором (раздел «Вендоры» парковки).
        static::saved(fn (self $v) => $v->wasChanged('vendor_id') || $v->wasRecentlyCreated ? Vendor::markOnPark($v->vendor_id) : null);
        // Машина одна (`Sale`): связали или отвязали предложение — файлы переходят, тождество ТС зеркалит предложение.
        static::saved(function (self $v) {
            if ($v->wasChanged('offer_id') || ($v->wasRecentlyCreated && $v->offer_id)) {
                Sale::relinked($v, $v->wasRecentlyCreated ? null : $v->getOriginal('offer_id'));
            }
            if ($v->offer_id && ($v->wasRecentlyCreated || $v->wasChanged(['offer_id', 'value', ...array_keys(Sale::MAP)]))) {
                Sale::toOffer($v);
            }
        });
        // Тип ТС предложения берётся и с парковки — сменили категорию или связь, пересчитываем колонку предложения.
        static::saved(function (self $v) {
            if ($v->wasChanged(['category', 'offer_id']) || $v->wasRecentlyCreated) {
                foreach (array_filter([$v->offer_id, $v->getOriginal('offer_id')]) as $id) {
                    if ($offer = Offer::withoutGlobalScopes()->find($id)) {
                        Offer::withoutGlobalScopes()->whereKey($id)->toBase()->update(['vehicle_category' => $offer->guessCategory()->value]);
                    }
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['state' => VehicleState::class, 'category' => Category::class, 'oversize' => 'bool', 'damage_zones' => 'array', 'flags' => 'array', 'docs_required' => 'array',
            'accepted_at' => 'datetime', 'released_at' => 'datetime', 'cancelled_at' => 'datetime', 'idle_noticed_at' => 'datetime', 'year' => 'int',
            'contract_at' => 'date', 'storage_billed_until' => 'date', 'storage_rate' => 'float', 'sold_at' => 'date', 'buyer_free_until' => 'date', 'billing_cadence' => Cadence::class];
    }

    public function setRefAttribute(?string $value): void
    {
        $value = trim((string) $value) ?: null;
        $this->attributes['ref'] = $value;
        $this->attributes['ref_key'] = $value ? self::keyFor($value) : null;
    }

    public function setVinAttribute(?string $value): void
    {
        $this->attributes['vin'] = Vin::normalize($value);
    }

    public function setPlateAttribute(?string $value): void
    {
        $this->attributes['plate'] = Patterns::plateKey($value);
    }

    public static function keyFor(string $ref): string
    {
        return (string) Code::key($ref);
    }

    /**
     * Поиск ТС — одна дверь для «Наличия», «Заявок», «Оплат» парковки, подсказки ТС и шапок: номер убытка куском в любой
     * записи, VIN, госномер (латиницей или кириллицей, без региона, с пробелами), СТС, ПТС, полис, телефон, марка, модель и
     * год по словам (`Search\CarWords`).
     */
    public function scopeSearch(Builder $q, string|Term $term): Builder
    {
        $t = Term::of($term);
        if ($t->blank()) {
            return $q;
        }
        $compact = Term::like(mb_strtoupper((string) preg_replace('/\s+/u', '', $t->raw)));

        return $q->where(fn ($w) => $w
            ->when($t->key, fn ($w, $key) => $w->orWhere('park_vehicles.ref_key', 'like', Term::like($key)))
            ->when($t->vin, fn ($w, $vin) => $w->orWhere('park_vehicles.vin', 'like', Term::like($vin)))
            ->when($t->plate, fn ($w, $p) => $w->orWhereRaw(Term::latinSql('park_vehicles.plate').' like ?', [Term::like($p)]))
            ->orWhereRaw("upper(replace(coalesce(park_vehicles.sts, ''), ' ', '')) like ?", [$compact])
            ->orWhereRaw("upper(replace(coalesce(park_vehicles.pts, ''), ' ', '')) like ?", [$compact])
            ->orWhere('park_vehicles.policy_no', 'ilike', Term::like($t->raw))
            ->when($t->phone, fn ($w, $p) => $w->orWhereRaw(Term::digitsSql("concat_ws(' ', park_vehicles.contact_phone, park_vehicles.pickup_phone)").' like ?', [Term::like($p)]))
            ->orWhere(fn ($all) => CarWords::apply($all, $t, 'park_vehicles')));
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(CarModel::class, 'model_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return list<Flag> */
    public function flagList(): array
    {
        return Flag::fromList($this->flags);
    }

    /** Что вендор ждёт после приёма: своё у машины, иначе правило вендора. @return list<DocRequirement> */
    public function docsRequired(): array
    {
        $values = $this->docs_required ?: ($this->vendor?->intake_docs ?? []);

        return array_values(array_filter(array_map(fn ($v) => DocRequirement::tryFrom((string) $v), $values)));
    }

    public function ownerParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'owner_party_id');
    }

    /** Покупатель, которому выдаём: физлицо из письма «продано» или из сделки CRM. */
    public function buyerParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'buyer_party_id');
    }

    /** Когда выставлять счёт за хранение: своё у ТС, иначе правило вендора. */
    public function cadence(): Cadence
    {
        return $this->billing_cadence ?? $this->vendor?->billing_cadence ?? Cadence::Monthly;
    }

    /**
     * Редактор письма вендору с актом и фото: после приёма, выдачи или отказа от получения.
     * Без вендора или у своего транспорта писать некому — null.
     */
    public function reportUrl(string $act, string $back): ?string
    {
        $vendor = $this->vendor;
        if (! $vendor || ! $vendor->kind->billable()) {
            return null;
        }
        $template = match ($act) {
            'refusal' => $vendor->refusal_template_id ?? Template::park('refusal')->id,
            'release' => Template::park('release')->id,
            default => $vendor->report_template_id ?? Template::park('intake')->id,
        };

        return '/mail/new?'.http_build_query(['car' => $this->id, 'template' => $template, 'act' => $act === 'intake' ? 'intake' : 'release', 'back' => $back]);
    }

    /** Выдача только по QR — у вендора включено. */
    public function releasesByQr(): bool
    {
        return (bool) $this->vendor?->release_by_qr;
    }

    /** Ссылка на анкету покупателя: одна на ТС, код заводится при первой нужде; заменить — `RenewPickupLink`. */
    public function pickupUrl(): string
    {
        if (! $this->pickup_code) {
            do {
                $code = Pass::freshCode();
            } while (self::withoutGlobalScope(City::SCOPE)->where('pickup_code', $code)->exists());
            $this->forceFill(['pickup_code' => $code])->saveQuietly();
        }

        return Surface::Site->url('/pickup/'.$this->pickup_code);
    }

    /** Ссылка на анкету, если она уже есть, — без заведения кода (блок «Ссылка на анкету» в деле). */
    public function pickupLink(): ?string
    {
        return $this->pickup_code ? Surface::Site->url('/pickup/'.$this->pickup_code) : null;
    }

    public function passes(): HasMany
    {
        return $this->hasMany(Pass::class)->latest('id');
    }

    /** Живой пропуск: последний не отозванный (выданный тоже — он и показывает «Выдан»). */
    public function pass(): ?Pass
    {
        return $this->passes()->whereNull('revoked_at')->first();
    }

    /** Отказ покупателя, «не продано», «не покупатель»: пропуск гаснет, анкета по той же ссылке снова пустая. */
    public function revokePass(string $reason): void
    {
        $this->passes()->whereNull('revoked_at')->whereNull('used_at')->update(['revoked_at' => now(), 'revoke_reason' => mb_substr($reason, 0, 255)]);
    }

    /** Телефон покупателя для `tel:` — нормализованный или как записан. */
    public function pickupPhoneDigits(): ?string
    {
        return $this->pickup_phone ? (Phone::normalize($this->pickup_phone) ?? preg_replace('/\D+/', '', $this->pickup_phone)) : null;
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'vehicle_id')->latest('issued_at')->latest('id');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class, 'vehicle_id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'vehicle_id')->latest('at');
    }

    public function lastInspection(InspectionKind $kind): ?Inspection
    {
        return $this->inspections->first(fn (Inspection $i) => $i->kind === $kind);
    }

    public function docs(): HasMany
    {
        return $this->hasMany(Doc::class, 'vehicle_id')->orderBy('direction', 'desc')->orderBy('id');
    }

    /** Бумаги вендору ещё не отправлены. */
    public function docsPending(): bool
    {
        return $this->docs->contains(fn (Doc $d) => $d->isOut() && ! $d->isDone());
    }

    /** Место на площадке: заглавными, без пробелов по краям; занятое другой ТС на стоянке — ошибка формы. */
    public static function takeSpot(?Yard $yard, ?string $spot, ?int $exceptId = null): ?string
    {
        $spot = $spot ? mb_strtoupper(trim($spot)) : null;
        // Без парковки (заведена по письмам или по факту) — места нет, его укажут вместе с парковкой.
        if (! $yard) {
            return null;
        }
        // Места по рядам расписаны и все заняты — принимать некуда; без рядов вместимость только подсказка.
        if (! $spot && $yard->rows && ! $yard->freeSpots()) {
            throw ValidationException::withMessages(['spot' => 'На «'.$yard->name.'» свободных мест нет']);
        }
        if ($spot && self::where('yard_id', $yard->id)->where('spot', $spot)->where('state', VehicleState::Stored)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists()) {
            throw ValidationException::withMessages(['spot' => 'Место '.$spot.' занято']);
        }

        return $spot;
    }

    /** Открытая заявка типа, если есть. */
    public function openRequest(RequestType $type): ?Request
    {
        return $this->requests->first(fn (Request $r) => $r->type === $type && $r->isOpen());
    }

    public function yard(): BelongsTo
    {
        return $this->belongsTo(Yard::class, 'yard_id');
    }

    /** Скрытие кадра — показ в продаже (CRM), дело на парковке видит все кадры. */
    protected function hides(Media $media): bool
    {
        return false;
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(Request::class, 'vehicle_id')->latest();
    }

    /** Ветки писем ТС; `threads_count` — тег «Писем нет». */
    public function threads(): HasMany
    {
        return $this->hasMany(Thread::class, 'vehicle_id')->park();
    }

    public function events(): HasMany
    {
        return $this->hasMany(VehicleEvent::class, 'vehicle_id')->latest('created_at');
    }

    /**
     * Что не так с VIN, словом для тега: «VIN отсутствует», «Ошибка в VIN» (не 17 знаков, буквы I/O/Q, у VIN
     * с контрольной цифрой — не сходится: северная Америка, WMI 1–5, и Китай на 9-й позиции). Null — VIN в порядке.
     */
    /** Писем к ТС не привязано — тревожный знак: заведена руками или тождество в письмах другое. */
    public function noLetters(): bool
    {
        return ($this->threads_count ?? $this->threads()->count()) === 0;
    }

    public function vinProblem(): ?string
    {
        if (! $this->vin) {
            return 'VIN отсутствует';
        }
        if (! VinDecoder::looksValid($this->vin) || ! VinDecoder::checkDigitOk($this->vin)) {
            return 'Ошибка в VIN';
        }

        return null;
    }

    /** Подпись в подтверждении «В продажу»: машина, госномер, убыток — чтобы видеть, что выставляешь. */
    public function saleLabel(): string
    {
        return implode(', ', array_filter([$this->titleWithYear(), $this->plate, $this->ref ? 'убыток '.$this->ref : null]));
    }

    /** Тип ТС заново: как у связанного предложения, иначе по марке и модели («Газель», «КАМАЗ»), не понять — легковой. */
    public function guessCategory(): Category
    {
        $offer = $this->offer_id ? Offer::find($this->offer_id, ['id', 'vehicle_category']) : null;

        return Category::tryFrom((string) $offer?->vehicle_category) ?? Category::guess($this->titleWithYear()) ?? Category::Passenger;
    }

    public function titleWithYear(): string
    {
        $name = trim(($this->brand?->name ?? '').' '.($this->model?->name ?? ''));

        // Без марки — номер убытка или госномер: «, 2019» само по себе ничего не говорит.
        return $name ? $name.($this->year ? ", {$this->year}" : '') : ($this->ref ?: ($this->plate ?: 'ТС'));
    }

    /**
     * Где ТС стояла по дням — из ленты: приём и перестановка дают площадку, погрузка — «в пути» (null).
     * Для хранения на лету: сутки в пути между площадками не считаются, ставка — по площадке того дня.
     *
     * Принята без парковки (по письмам или по факту) — площадка неизвестна (`yard_id` null), но ТС стоит, не в пути (`transit`).
     *
     * @return list<array{day: Carbon, yard_id: ?int, transit: bool}>
     */
    public function yardTimeline(): array
    {
        $types = [EventType::Accepted, EventType::Moved, EventType::Departed];
        // Список «Наличия» и закрытие месяца грузят ленту заранее: запрос на каждую ТС — это сотня запросов на страницу.
        $events = $this->relationLoaded('events')
            ? $this->events->filter(fn ($e) => in_array($e->type, $types, true))->sortBy([['created_at', 'asc'], ['id', 'asc']])
            : $this->events()->reorder()->whereIn('type', $types)->oldest('created_at')->oldest('id')->get();
        $out = [];
        foreach ($events as $e) {
            $p = $e->payload ?? [];
            $day = ! empty($p['day']) ? Carbon::parse($p['day'])->startOfDay() : $e->created_at->copy()->startOfDay();
            // Старые записи без yard_id — площадка нынешняя: до 22.09.2026 в ленте было только имя.
            $yardId = $e->type === EventType::Departed ? null : ($p['yard_id'] ?? $this->yard_id);
            $out[] = ['day' => $day, 'yard_id' => $yardId ? (int) $yardId : null, 'transit' => $e->type === EventType::Departed];
        }
        usort($out, fn ($a, $b) => $a['day'] <=> $b['day']);

        return $out;
    }

    public function daysStored(): ?int
    {
        // День приёма — первые сутки, как в начислении: иначе «Дней» в списке на единицу меньше, чем в счёте.
        // Календарные сутки, а не 24 часа: принята вчера в 14:05 — сегодня уже вторые сутки, как в начислении.
        return $this->accepted_at ? (int) $this->accepted_at->copy()->startOfDay()->diffInDays(($this->released_at ?? now())->copy()->startOfDay()) + 1 : null;
    }

    public function damages(): array
    {
        return array_values(array_filter(array_map(fn ($z) => DamageZone::labelOf($z), $this->damage_zones ?? [])));
    }

    public function log(EventType $type, ?User $by = null, array $payload = []): VehicleEvent
    {
        return $this->events()->create(['type' => $type, 'user_id' => $by?->id, 'payload' => $payload]);
    }
}
