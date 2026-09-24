<?php

namespace App\Vendors;

use App\Billing\Cadence;
use App\Billing\Party;
use App\Cars\Category;
use App\Mail\Account;
use App\Mail\Template;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Workflow\Track;
use App\Workflow\Workflow;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Вендор — страховая, лизинг или банк, откуда приходят машины: письма
 * привязываются к нему по адресам и доменам отправителей (`senders`), у него
 * маршруты по веткам, контакты по ролям, реквизиты, условия и договорной прайс.
 * Одна дверь `forSender` заменяет карту доменов в разборе и заказчика стоянки.
 */
#[Fillable([
    'name', 'kind', 'is_active', 'notes',
    'legal_name', 'inn', 'kpp', 'legal_address',
    'bank_name', 'bank_account', 'bank_corr', 'bank_bic', 'payment_purpose',
    'agreement_number', 'agreement_date', 'agreement_until',
    'deal_format', 'reward_kind', 'reward_value', 'payment_days', 'vat_included', 'answer_hours', 'silence_means_buy', 'binding_days',
    'storage_payer', 'buyer_pays_late', 'release_without_payment', 'release_by_qr', 'buyer_rate_multiplier', 'billing_cadence',
    'senders', 'parser', 'mail_account_id', 'party_id', 'report_template_id', 'refusal_template_id',
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
            'agreement_date' => 'date',
            'agreement_until' => 'date',
            'deal_format' => DealFormat::class,
            'reward_kind' => RewardKind::class,
            'vat_included' => 'bool',
            'silence_means_buy' => 'bool',
            'release_without_payment' => 'bool',
            'release_by_qr' => 'bool',
            'buyer_pays_late' => 'bool',
            'buyer_rate_multiplier' => 'float',
            'billing_cadence' => Cadence::class,
            'senders' => 'array',
            'parser' => Parser::class,
            'intake_docs' => 'array',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('contract')->useDisk('private');
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
     * тот же отбор, что у начисления (договорной прайс поверх базового), по всем парковкам. Нет ставки — null.
     *
     * @return array<string, ?string> категория => ставка
     */
    public function storageRates(): array
    {
        return collect(Category::cases())
            ->mapWithKeys(fn ($c) => [$c->label() => Tariff::ladderLabel(Tariff::ladder($this->id, null, $c, TariffService::Storage))])
            ->all();
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

    /** Кому писать: сначала по роли, потом основной, потом любой с почтой. */
    public function defaultContact(?ContactRole ...$roles): ?Contact
    {
        $contacts = $this->contacts;
        foreach (array_filter($roles) as $role) {
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

    /** @return list<string> адреса, которые вендор всегда держит в копии */
    public function ccEmails(): array
    {
        return $this->contacts->where('always_cc', true)->pluck('email')->filter()->values()->all();
    }

    /** Договор истёк — на карточке это тревожный чип. */
    public function agreementExpired(): bool
    {
        return $this->agreement_until !== null && $this->agreement_until->isPast();
    }

    /** Адрес или домен отправителя → вендор. Точный адрес важнее домена: tbank.ru — целый банк. */
    public static function forSender(?string $email): ?self
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }
        $domain = (string) preg_replace('/.*@/u', '', $email);

        return self::whereJsonContains('senders', $email)->first()
            ?? self::whereJsonContains('senders', $domain)->first();
    }

    /** Строки «домен или адрес» из формы → список без дублей и пустот. */
    public static function parseSenders(?string $raw): array
    {
        $items = preg_split('/[\s,;]+/u', mb_strtolower((string) $raw)) ?: [];

        return array_values(array_unique(array_filter(array_map(fn ($s) => trim($s, " \t@"), $items))));
    }
}
