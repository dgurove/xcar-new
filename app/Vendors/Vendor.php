<?php

namespace App\Vendors;

use App\Billing\Cadence;
use App\Billing\Party;
use App\Cars\Category;
use App\Mail\Account;
use App\Mail\Scope;
use App\Mail\Template;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Вендор — страховая, лизинг или банк, откуда приходят машины. Две стороны: CRM (продажа предложений — условия
 * сделки, маршруты, ящик, «Реализация», адреса и разбор писем с предложениями `senders`/`parser`, НДС цен
 * предложений) и парковка (реквизиты, договор, хранение, прайс, контакты по убыткам и хранению, адреса и разбор
 * заявок на приёмку `park_senders`/`park_parser`). Общие — имя, тип и «работаем».
 * Одна дверь `forSender` находит вендора письма по адресам своей стороны.
 */
#[Fillable([
    'name', 'kind', 'is_active', 'on_park', 'notes',
    'legal_name', 'inn', 'kpp', 'legal_address',
    'bank_name', 'bank_account', 'bank_corr', 'bank_bic', 'payment_purpose',
    'agreement_number', 'agreement_date', 'agreement_until',
    'deal_format', 'reward_kind', 'reward_value', 'payment_days', 'vat_included', 'offers_include_vat', 'answer_hours', 'silence_means_buy', 'binding_days',
    'storage_payer', 'buyer_pays_late', 'release_without_payment', 'release_by_qr', 'buyer_rate_multiplier', 'billing_cadence',
    'senders', 'parser', 'park_senders', 'park_parser', 'mail_account_id', 'party_id', 'report_template_id', 'refusal_template_id',
    'intake_docs', 'intake_note',
])]
class Vendor extends Model implements HasMedia
{
    use InteractsWithMedia;

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
            'vat_included' => 'bool',
            'offers_include_vat' => 'bool',
            'silence_means_buy' => 'bool',
            'release_without_payment' => 'bool',
            'release_by_qr' => 'bool',
            'buyer_pays_late' => 'bool',
            'buyer_rate_multiplier' => 'float',
            'billing_cadence' => Cadence::class,
            'senders' => 'array',
            'parser' => Parser::class,
            'park_senders' => 'array',
            'park_parser' => Parser::class,
            'intake_docs' => 'array',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('contract')->useDisk('private');
    }

    /** Вендоры парковки: были ТС или свой прайс, или заведён на парковке. Вендоры одной продажи — только в CRM. */
    public function scopeOnPark($query)
    {
        return $query->where('on_park', true);
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

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    public function reportTemplate(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'report_template_id');
    }

    public function refusalTemplate(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'refusal_template_id');
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
        $domain = (string) preg_replace('/.*@/u', '', $email);

        return self::whereJsonContains($column, $email)->first()
            ?? self::whereJsonContains($column, $domain)->first();
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
