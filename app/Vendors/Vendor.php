<?php

namespace App\Vendors;

use App\Billing\Cadence;
use App\Billing\Party;
use App\Billing\Seller;
use App\Cars\Category;
use App\Mail\Account;
use App\Mail\Scope;
use App\Media\MediaUrl;
use App\Offers\Offer;
use App\Offers\Tag;
use App\Park\City;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Park\Yard;
use App\Search\Term;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Вендор — страховая, лизинг или банк, откуда приходят машины. Две стороны: CRM (продажа предложений — условия
 * сделки, маршруты, ящик, «Реализация», адреса и разбор писем с предложениями `senders`/`parser`, НДС цен
 * предложений) и парковка (реквизиты, договор, хранение, прайс, контакты по убыткам и хранению, адреса и разбор
 * заявок на приёмку `park_senders`; разбор их общий, `ParkExtractor`). Общие — имя, тип и «работаем».
 * Одна дверь `forSender` находит вендора письма по адресам своей стороны.
 */
#[Fillable([
    'name', 'kind', 'is_active', 'on_park', 'notes',
    'legal_name', 'inn', 'kpp', 'legal_address',
    'bank_name', 'bank_account', 'bank_corr', 'bank_bic', 'payment_purpose',
    'agreement_number', 'agreement_date', 'agreement_until',
    'deal_format', 'to_gallery', 'reward_kind', 'reward_value', 'payment_days', 'offers_include_vat', 'answer_hours', 'silence_means_buy', 'binding_days',
    'storage_payer', 'buyer_pays_late', 'release_without_payment', 'release_by_qr', 'rate_by_value', 'buyer_rate_multiplier', 'billing_cadence',
    'senders', 'parser', 'park_senders', 'mail_account_id', 'party_id', 'report_template_id', 'refusal_template_id', 'home_yard_id', 'park_seller',
    'intake_docs', 'intake_note', 'audience_rules', 'intake_report_docs',
])]
class Vendor extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** Правка вендора (адреса отправителей, разбор) — `forSender` спрашивает базу заново. */
    protected static function booted(): void
    {
        static::saved(fn () => self::forgetSenders());
        // Сменили парковку по умолчанию — город ТС вендора без своей парковки следует за ней (`Park\City`).
        static::saved(function (self $v) {
            if ($v->wasChanged('home_yard_id')) {
                $city = City::ofVendor($v->id) ?? City::main();
                Vehicle::withoutGlobalScope(City::SCOPE)->whereNull('yard_id')->where('vendor_id', $v->id)
                    ->whereNotIn('state', [VehicleState::Released, VehicleState::Cancelled])->update(['city_id' => $city]);
            }
        });
        static::deleted(fn () => self::forgetSenders());
    }

    protected function casts(): array
    {
        return [
            'kind' => Kind::class,
            'is_active' => 'bool',
            'on_park' => 'bool',
            'agreement_date' => 'date',
            'agreement_until' => 'date',
            'deal_format' => DealFormat::class,
            'reward_kind' => RewardKind::class,
            'offers_include_vat' => 'bool',
            'to_gallery' => 'bool',
            'silence_means_buy' => 'bool',
            'release_without_payment' => 'bool',
            'release_by_qr' => 'bool',
            'rate_by_value' => 'bool',
            'buyer_pays_late' => 'bool',
            'buyer_rate_multiplier' => 'float',
            'billing_cadence' => Cadence::class,
            'park_seller' => Seller::class,
            'senders' => 'array',
            'audience_rules' => 'array',
            'parser' => Parser::class,
            'park_senders' => 'array',
            'intake_docs' => 'array',
            'intake_report_docs' => 'array',
        ];
    }

    /** Лупа вендоров: имя, юридическое имя, ИНН. */
    public function scopeSearch(Builder $q, string|Term $term): Builder
    {
        $t = Term::of($term);

        return $t->blank() ? $q : $q->where(fn ($s) => $s->where('vendors.name', 'ilike', Term::like($t->raw))
            ->orWhere('vendors.legal_name', 'ilike', Term::like($t->raw))->orWhere('vendors.inn', 'like', Term::like($t->raw)));
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('contract')->useDisk('private');
        $this->addMediaCollection('logo')->useDisk('media')->singleFile();
    }

    /**
     * Все вендоры с логотипами одним запросом на запрос страницы: логотип стоит перед каждым упоминанием вендора
     * (строки, чипы, счета его контрагента), и без этого каждая строка списка ходила бы в медиатеку сама.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function badges(): \Illuminate\Support\Collection
    {
        return once(fn () => self::with(['media' => fn ($q) => $q->where('collection_name', 'logo')])->get(['id', 'name', 'kind', 'party_id'])->keyBy('id'));
    }

    /**
     * Вендор предложения: у вендора парковки, который в CRM — другой (`crm_vendor_id`: «АльфаСтрахование СПб» →
     * «АльфаСтрахование»), — тот. Ставит `Offer::saving` — из письма, с парковки (`Sale::toOffer`) или руками.
     */
    public static function forCrm(?int $id): ?int
    {
        return $id ? (once(fn () => self::whereNotNull('crm_vendor_id')->pluck('crm_vendor_id', 'id')->all())[$id] ?? $id) : null;
    }

    /** Закупочная от оценочной стоимости (`rate_by_value`, Альфа): только у такого вендора поле «Оценочная» в CRM. */
    public static function ratesByValue(?int $id): bool
    {
        return $id !== null && in_array($id, once(fn () => self::where('rate_by_value', true)->pluck('id')->all()), true);
    }

    /** НДС цен предложений этого вендора по умолчанию — одна дверь для письма, ручного черновика и смены вендора. */
    public static function offersVat(?int $id): bool
    {
        return (bool) ($id ? self::whereKey($id)->value('offers_include_vat') : false);
    }

    /** Вендор, чей это контрагент счёта, — для логотипа перед именем контрагента. */
    public static function ofParty(?int $partyId): ?self
    {
        return $partyId ? self::badges()->firstWhere('party_id', $partyId) : null;
    }

    /**
     * Продаёт по договору комиссии (Совкомбанк): машину забирают без оплаты, ПРАЙМ платит ему после продажи (10.10.2026).
     * Одна проверка на сделку, принятие и шторку.
     */
    public function isCommission(): bool
    {
        return $this->deal_format === DealFormat::Commission;
    }

    public function logoUrl(): ?string
    {
        $media = (self::badges()->get($this->id) ?? $this)->getFirstMedia('logo');

        return $media ? MediaUrl::for($media) : null;
    }

    /** Вендоры парковки: были ТС или свой прайс, или заведён на парковке. Вендоры одной продажи — только в CRM. */
    /** Вендоры парковки; управляющему с городом — только своего города (`Park\City`): списки, выбор в деле, реквизиты. */
    public function scopeOnPark($query)
    {
        return $query->where('on_park', true)->when(City::viewing(), fn ($q, $city) => $q->ofParkCity($city));
    }

    /** С вендором начала работать парковка (пришла ТС, завели прайс) — он появляется в её «Вендорах». */
    public static function markOnPark(?int $id): void
    {
        if ($id) {
            self::whereKey($id)->where('on_park', false)->update(['on_park' => true]);
        }
    }

    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    /** Метки, которые его предложения получают сами (11.10.2026, `Offers\VendorTags`); та же связь, что у метки. */
    public function defaultTags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'tag_vendor')->orderBy('sort');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class)->orderBy('is_default', 'desc')->orderBy('name');
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class);
    }

    /**
     * Ставка хранения по категориям ТС словами («250…450 ₽/сут по стоимости») — сводка для условий вендора:
     * тот же отбор, что у начисления (договорной прайс поверх базового). Договорные цены бывают заданы по
     * парковкам (у Альфы СПб — только Краснопутиловская), поэтому смотрим и каждую такую парковку: цена одна —
     * пишем её, разные — «по парковкам» (подробности в пилюле «Тарифы»). Нет ставки нигде — null.
     *
     * @return array<string, ?string> категория => ставка
     */
    public function storageRates(): array
    {
        $yards = Tariff::where('vendor_id', $this->id)->whereNotNull('yard_id')->distinct()->pluck('yard_id');

        return collect(Category::cases())->mapWithKeys(function (Category $c) use ($yards) {
            $labels = collect([null, ...$yards])
                ->map(fn (?int $yard) => Tariff::ladderLabel(Tariff::ladderWhole($this->id, $yard, $c, TariffService::Storage)))
                ->filter()->unique()->values();

            return [$c->label() => match ($labels->count()) {
                0 => null, 1 => $labels->first(), default => 'по парковкам'
            }];
        })->all();
    }

    /** Наименование услуги хранения в отчёт-акте и его выгрузке: у страховой — повреждённое ТС (форма Питера, 09.10.2026). */
    public function storageServiceTitle(): string
    {
        return $this->kind === Kind::Insurer ? 'Хранение повреждённого ТС' : 'Хранение ТС';
    }

    /** Вендоры города парковки (`Park\City`): его письма относятся к городу или его ТС стоят в нём. */
    public function scopeOfParkCity($q, int $city)
    {
        return $q->where(fn ($w) => $w->whereIn('vendors.id', City::vendorIds($city))
            ->orWhereIn('vendors.id', Vehicle::query()->whereNotNull('vendor_id')->where('city_id', $city)->select('vendor_id')));
    }

    /** Парковка по умолчанию: на неё встают ТС вендора, чью парковку не назвал контакт, и по ней город его писем. */
    public function homeYard(): BelongsTo
    {
        return $this->belongsTo(Yard::class, 'home_yard_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'mail_account_id');
    }

    public function workflow(Track $track): ?Workflow
    {
        return $this->workflows->firstWhere('track', $track);
    }

    /** Маршрут ветки, заведённый при первом обращении. */
    public function workflowOrNew(Track $track): Workflow
    {
        $workflow = $this->workflows()->firstOrCreate(['track' => $track]);
        $this->unsetRelation('workflows');

        return $workflow;
    }

    /**
     * Кому писать: сначала по роли, потом основной, потом любой с почтой — только среди контактов той же стороны,
     * что и роли (без ролей — парковка): письмо о хранении не уйдёт в реализацию, предложение — в убытки.
     */
    public function defaultContact(?ContactRole ...$roles): ?Contact
    {
        $roles = array_values(array_filter($roles));
        $contacts = $this->sideContacts(($roles[0] ?? null)?->isSale() ?? false);
        foreach ($roles as $role) {
            if ($c = $contacts->first(fn (Contact $c) => $c->role === $role && $c->email)) {
                return $c;
            }
        }

        return $contacts->first(fn (Contact $c) => $c->is_default && $c->email) ?? $contacts->first(fn (Contact $c) => $c->email);
    }

    public function email(?ContactRole ...$roles): ?string
    {
        return $this->defaultContact(...$roles)?->email;
    }

    /**
     * Кому письмо по продаже: контакт «Реализация» не из копии, сначала отмеченный основным; нет — null, письмо идёт
     * ответом тому, кто писал (`MailController::compose`).
     */
    public function saleTo(): ?string
    {
        $to = $this->sideContacts(true)->filter(fn (Contact $c) => $c->email && ! $c->always_cc);

        return ($to->firstWhere('is_default', true) ?? $to->first())?->email;
    }

    /** @return list<string> адреса, которые вендор всегда держит в копии, — среди контактов своей стороны */
    public function ccEmails(bool $sale = false): array
    {
        return $this->sideContacts($sale)->where('always_cc', true)->pluck('email')->filter()->values()->all();
    }

    /** Контакты стороны: «Реализация» — CRM, остальные роли — парковка. */
    public function sideContacts(bool $sale): Collection
    {
        return $this->contacts->filter(fn (Contact $c) => $c->role?->isSale() === $sale)->values();
    }

    /** Договор истёк — на карточке это тревожный чип. */
    public function agreementExpired(): bool
    {
        return $this->agreement_until !== null && $this->agreement_until->isPast();
    }

    /** Адрес или домен отправителя → вендор по спискам стороны ящика. Точный адрес важнее домена: tbank.ru — целый банк. */
    public static function forSender(?string $email, Scope $scope): ?self
    {
        $column = self::sendersColumn($scope);
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }
        // Разбор письма спрашивает вендора на каждое письмо: ответ помнится в процессе минуту, правка вендора — сброс.
        if (time() - self::$sendersAt >= 60) {
            self::$senders = [];
            self::$sendersAt = time();
        }
        $key = $column.':'.$email;
        if (array_key_exists($key, self::$senders)) {
            return self::$senders[$key] ? self::find(self::$senders[$key]) : null;
        }
        $domain = (string) preg_replace('/.*@/u', '', $email);
        $vendor = self::whereJsonContains($column, $email)->first()
            ?? self::whereJsonContains($column, $domain)->first();
        self::$senders[$key] = $vendor?->id;

        return $vendor;
    }

    /** @var array<string, ?int> «сторона:адрес» → id вендора (`forSender`) */
    private static array $senders = [];

    private static int $sendersAt = 0;

    /** Адреса вендоров поменялись — `forSender` спрашивает базу заново. */
    public static function forgetSenders(): void
    {
        self::$senders = [];
    }

    public static function sendersColumn(Scope $scope): string
    {
        return $scope === Scope::Park ? 'park_senders' : 'senders';
    }

    /** Адрес уже в списке другого вендора той же стороны — текст ошибки формы, иначе null. */
    public static function takenSender(array $senders, Scope $scope, self $except): ?string
    {
        foreach ($senders as $sender) {
            if ($other = self::whereJsonContains(self::sendersColumn($scope), $sender)->whereKeyNot($except->id)->first()) {
                return "{$sender} уже у «{$other->name}»";
            }
        }

        return null;
    }

    /** Строки «домен или адрес» из формы → список без дублей и пустот. */
    public static function parseSenders(?string $raw): array
    {
        $items = preg_split('/[\s,;]+/u', mb_strtolower((string) $raw)) ?: [];

        return array_values(array_unique(array_filter(array_map(fn ($s) => trim($s, " \t@"), $items))));
    }
}
