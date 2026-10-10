<?php

namespace App\Billing;

use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Garage\Car as GarageCar;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Park\City;
use App\Park\Vehicle;
use App\Search\Term;
use App\Support\Demo\HidesDemo;
use App\Support\Sort;
use App\Users\User;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Счёт. `issued` — нам должны, `owed` — должны мы (перечисление вендору по
 * договору комиссии). Остаток и просрочка считаются, не хранятся; PDF — снимок
 * в момент выставления, коллекция `file` на закрытом диске. `seller` — кто из нас
 * выставил: реквизиты в документах и свой ряд номеров. Реестр (`isRegistry`) — счёт парковки плательщику за месяц
 * без своей ТС: машины — строками хранения со своим `vehicle_id`, долг машины — её доля (`Ledger::shareOf`).
 */
#[Fillable(['seller', 'park', 'direction', 'year', 'number', 'external_no', 'kind', 'month', 'party_id', 'vehicle_id', 'deal_id', 'offer_id', 'issued_at', 'due_at', 'vat', 'vat_rate', 'vat_on_top', 'total', 'paid', 'state', 'paid_at',
    'overdue_at', 'reminded_at', 'sent_at', 'voided_at', 'void_reason', 'notes', 'created_by', 'car_title', 'credit_deal_id'])]
class Invoice extends Model implements HasMedia
{
    use HidesDemo;
    use InteractsWithMedia;

    protected $table = 'billing_invoices';

    /** Управляющий с городом видит счета только своего города (`Park\City`). */
    protected static function booted(): void
    {
        static::addGlobalScope(City::SCOPE, fn (Builder $q) => ($city = City::viewing()) ? City::invoices($q, $city) : null);
    }

    public const VAT = 20;

    protected function casts(): array
    {
        return ['seller' => Seller::class, 'kind' => ChargeKind::class, 'state' => InvoiceState::class, 'month' => 'date', 'issued_at' => 'date', 'due_at' => 'date', 'paid_at' => 'date', 'vat' => 'bool', 'vat_rate' => 'int', 'vat_on_top' => 'bool', 'park' => 'bool',
            'total' => 'float', 'paid' => 'float', 'overdue_at' => 'datetime', 'reminded_at' => 'datetime', 'sent_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('file')->useDisk('private')->singleFile();
        $this->addMediaCollection('act')->useDisk('private')->singleFile();
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /** Счёт-реестр за месяц: ТС у строк, не у счёта. */
    public function isRegistry(): bool
    {
        return $this->month !== null && $this->vehicle_id === null;
    }

    /** За что счёт словами в строках списков: ТС, реестр за месяц, сделка или вид. */
    public function what(): string
    {
        return match (true) {
            (bool) $this->vehicle => $this->vehicle->titleWithYear(),
            $this->isRegistry() => 'реестр за '.mb_strtolower($this->month->translatedFormat('F')),
            (bool) $this->deal_id => 'сделка',
            default => $this->kind->label(),
        };
    }

    /**
     * Ждёт письма в бухгалтерию: выставлен страховой стороне (реестр, вендор или страхователь) и ещё не уходил. Счёт
     * покупателю письмом не шлют — его берут на выдаче, в «Отправить» его нет.
     */
    /**
     * Поиск счёта — одна дверь для «Оплат» CRM и парковки и шапок: номер («счёт 12», «№ 12», голое число) и чужой номер,
     * плательщик, текст услуги, менеджер, ТС предложения (`Offer::search`) и ТС парковки, в том числе любая машина
     * реестра (`Vehicle::search`).
     */
    public function scopeSearch(Builder $q, string|Term $term): Builder
    {
        $t = Term::of($term);
        if ($t->blank()) {
            return $q;
        }
        $like = Term::like($t->raw);
        $name = fn ($u) => $u->where('name', 'ilike', $like);
        $car = fn ($v) => $v->search($t);

        return $q->where(fn ($s) => $s
            ->when($t->invoice, fn ($x, $n) => $x->orWhere('billing_invoices.number', $n))
            ->orWhere('billing_invoices.external_no', 'ilike', $like)
            ->orWhere('billing_invoices.car_title', 'ilike', $like)
            ->orWhereHas('party', $name)
            ->orWhereHas('charges', fn ($c) => $c->where('kind', ChargeKind::Service->value)->where('title', 'ilike', $like))
            ->orWhereHas('deal.buyer', $name)
            ->orWhereHas('garageCar.manager', $name)
            ->orWhereHas('garagePayoutCar.manager', $name)
            ->orWhereHas('offer', $offer = fn ($o) => $o->searchCrm($t))
            ->orWhereHas('deal.offer', $offer)
            ->orWhereHas('garageCar.offer', $offer)
            ->orWhereHas('vehicle', $car)
            // Реестр находится по любой своей машине.
            ->orWhereHas('charges.vehicle', $car));
    }

    public function scopeToSend(Builder $q): Builder
    {
        return $q->where('direction', 'issued')->where('state', InvoiceState::Issued)->whereNull('sent_at')
            ->where(fn ($w) => $w->where(fn ($r) => $r->whereNull('vehicle_id')->whereNotNull('month'))
                ->orWhereIn('party_id', Vendor::whereNotNull('party_id')->select('party_id'))
                ->orWhereIn('party_id', Vehicle::whereNotNull('owner_party_id')->select('owner_party_id')));
    }

    /** Счета, где есть эта ТС: свои (`vehicle_id`) и реестры, в строках которых она есть. */
    public function scopeForVehicle(Builder $q, int $vehicleId): Builder
    {
        return $q->where(fn ($w) => $w->where('vehicle_id', $vehicleId)
            ->orWhere(fn ($r) => $r->whereNull('vehicle_id')->whereNotNull('month')->whereIn('id', Charge::where('vehicle_id', $vehicleId)->whereNotNull('invoice_id')->select('invoice_id'))));
    }

    /** ТС реестра — по строкам, в порядке строк. @return \Illuminate\Support\Collection<int, Vehicle> */
    public function registryVehicles()
    {
        $this->loadMissing('charges.vehicle.brand', 'charges.vehicle.model');

        return $this->charges->pluck('vehicle')->filter()->unique('id')->values();
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** Сделка, в которую засчитана разовая оплата (ручная ссылка с машиной), — не сделка счёта. */
    public function creditDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'credit_deal_id');
    }

    /** Машина счёта: своя у разовой оплаты, сделки или гаража. */
    public function carOffer(): ?Offer
    {
        return $this->offer ?? $this->deal?->offer ?? ($this->relationLoaded('garageCar') ? $this->garageCar : GarageCar::ofInvoice($this))?->offer;
    }

    /** Машина словами для ссылки и строк: «Toyota Camry, № 2610041229», у ТС не из системы — как вписали. */
    public function carLine(): ?string
    {
        if ($offer = $this->carOffer()) {
            return $offer->title().(OfferNumber::isPublic($offer) ? ', № '.$offer->number : '');
        }

        return $this->car_title ?: null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class, 'invoice_id')->orderBy('id');
    }

    /** Принятые оплаты — те, что в `paid`. Заявленные и не поступившие — отдельно. */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id')->whereNull('voided_at')->where('state', PaymentState::Confirmed)->orderBy('paid_at');
    }

    /** Заявленные менеджером: платёжка приложена, сотрудник ещё не подтвердил. */
    public function claims(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id')->where('state', PaymentState::Claimed)->orderBy('id');
    }

    /** Все оплаты для ленты: принятые, заявленные, не поступившие; отменённые — нет. */
    public function allPayments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id')->whereNull('voided_at')->orderBy('id');
    }

    /** Сколько заявлено и ждёт подтверждения. */
    /**
     * Заявлено к оплате. Экраны берут уже загруженные заявки (списки «Денег», положение) — без запроса на каждый счёт;
     * действия, которые по этому числу решают (новая заявка, ссылка на оплату), просят `fresh` — из базы.
     */
    public function claimed(bool $fresh = false): float
    {
        return round((float) (! $fresh && $this->relationLoaded('claims') ? $this->claims->sum('amount') : $this->claims()->sum('amount')), 2);
    }

    public function scopeOfSeller($q, Seller $seller)
    {
        return $q->where('seller', $seller->value);
    }

    /**
     * Счета парковки — по ТС и реестры, от какого бы продавца ни были: Питер выставляет от ПРАЙМ
     * (`vendors.park_seller`), поэтому продавец ИП парковку больше не определяет.
     */
    public function scopeOfPark($q)
    {
        return $q->where($q->getModel()->qualifyColumn('park'), true);
    }

    /** Ссылки на оплату; открытая у счёта одна. */
    public function payLinks(): HasMany
    {
        return $this->hasMany(PayLink::class, 'invoice_id')->orderBy('id');
    }

    public function openLink(): ?PayLink
    {
        return $this->relationLoaded('payLinks') ? $this->payLinks->first->isOpen() : $this->payLinks()->where('state', PayLinkState::Open)->first();
    }

    /** Долг за перевозку из логистики: партнёру или перевозчику (`Logistics\Actions\SettleCost`). */
    public function isTransport(): bool
    {
        return $this->direction === 'owed' && $this->kind === ChargeKind::Tow && ! $this->park;
    }

    public function isAgentFee(): bool
    {
        return $this->direction === 'owed' && $this->kind === ChargeKind::AgentFee;
    }

    /**
     * Что видит менеджер: счета его контрагенту и счета по его сделкам (плательщик —
     * его покупатель). Одна дверь для списка, страницы, PDF и заявки об оплате.
     */
    public function scopeVisibleToManager($q, User $user)
    {
        return $q->where(fn ($w) => $w
            ->when($user->party_id, fn ($x) => $x->where('party_id', $user->party_id), fn ($x) => $x->whereRaw('false'))
            ->orWhereHas('deal', fn ($d) => $d->where('buyer_id', $user->id)));
    }

    public function isVisibleToManager(User $user): bool
    {
        return ($user->party_id && $this->party_id === $user->party_id) || ($this->deal_id && $this->deal?->buyer_id === $user->id);
    }

    public function isOwed(): bool
    {
        return $this->direction === 'owed';
    }

    /**
     * Сортировка списков счетов (CRM «Оплаты», «Деньги» парковки): по умолчанию новые сверху — владелец 06.10.2026: по
     * сроку счета без срока уходили в конец и порядок выглядел случайным.
     */
    public const SORTS = ['fresh' => ['Дата счёта', 'desc'], 'due' => ['Срок оплаты', 'asc'], 'amount' => ['Сумма', 'desc'], 'rest' => ['Остаток', 'desc']];

    public function scopeSorted(Builder $q, Sort $sort): Builder
    {
        $dir = $sort->dir();
        match ($sort->key) {
            'due' => $q->orderByRaw("billing_invoices.due_at {$dir} nulls last"),
            'amount' => $q->orderBy('billing_invoices.total', $dir),
            'rest' => $q->orderByRaw("(case when billing_invoices.state = 'void' then 0 else greatest(billing_invoices.total - billing_invoices.paid, 0) end) {$dir}"),
            default => $q->orderBy('billing_invoices.issued_at', $dir),
        };

        return $q->orderBy('billing_invoices.id', $dir);
    }

    public function remaining(): float
    {
        return $this->state === InvoiceState::Void ? 0 : max(0, round($this->total - $this->paid, 2));
    }

    public function isOverdue(): bool
    {
        return $this->state === InvoiceState::Issued && $this->remaining() > 0 && $this->due_at && $this->due_at->isPast() && ! $this->due_at->isToday();
    }

    /** Сколько полных дней прошло после срока. */
    public function overdueDays(): int
    {
        return $this->due_at ? (int) $this->due_at->startOfDay()->diffInDays(now()->startOfDay()) : 0;
    }

    public function isPartial(): bool
    {
        return $this->state === InvoiceState::Issued && $this->paidMoney() > 0 && $this->remaining() > 0;
    }

    /**
     * Оплачено деньгами: без зачёта удержанного вознаграждения (`PaymentSource::Offset`) — это не оплата, а строка счёта,
     * которую менеджер оставил себе. Иначе счёт «удерживает сам» с порога горел «Частично».
     */
    public function paidMoney(): float
    {
        $offset = $this->relationLoaded('payments')
            ? $this->payments->where('source', PaymentSource::Offset)->sum('amount')
            : ($this->paid > 0 ? (float) $this->payments()->where('source', PaymentSource::Offset)->sum('amount') : 0);

        return max(0, round((float) $this->paid - $offset, 2));
    }

    /** Светофор: зелёный — оплачен, жёлтый — срок в три дня, красный — просрочен, серый — аннулирован, без тона — ждём. */
    public function light(): ?string
    {
        return match (true) {
            $this->state === InvoiceState::Void => 'closed',
            $this->state === InvoiceState::Paid => 'open',
            $this->isOverdue() => 'danger',
            $this->due_at?->lte(now()->addDays(3)) => 'urgent',
            default => null,
        };
    }

    /** Разовая оплата наших услуг из CRM — без сделки и машины (`IssueServiceInvoice`). */
    public function isService(): bool
    {
        return $this->kind === ChargeKind::Service && ! $this->deal_id && ! $this->vehicle_id;
    }

    /** Наименование позиции в чеке ЮKassa: у разовой оплаты — её услуга, у сделки — «Подбор ТС марка модель». */
    public function receiptTitle(): string
    {
        if ($this->isService()) {
            return (string) ($this->relationLoaded('charges') ? $this->charges->first()?->title : $this->charges()->value('title'));
        }

        return trim('Подбор ТС '.($this->offer?->titleWithYear() ?? ''));
    }

    /**
     * Счета раздела «Оплаты» в CRM: по сделкам, по машинам гаража (счёт и выплата менеджеру — без сделки, но в
     * «Расчётах с менеджерами» они есть, 06.10.2026) и разовые оплаты услуг.
     */
    public function scopeCrmMoney($q)
    {
        return $q->where(fn ($w) => $w->whereNotNull('deal_id')
            ->orWhereIn('id', fn ($g) => $g->select('invoice_id')->from('garage_cars')->whereNotNull('invoice_id'))
            ->orWhereIn('id', fn ($g) => $g->select('payout_invoice_id')->from('garage_cars')->whereNotNull('payout_invoice_id'))
            ->orWhere(fn ($s) => $s->where('kind', ChargeKind::Service->value)->whereNull('vehicle_id'))
            // Перевозка из логистики, не парковочная: долг партнёру или перевозчику.
            ->orWhere(fn ($s) => $s->where('direction', 'owed')->where('kind', ChargeKind::Tow->value)->where('park', false)));
    }

    /** Машина гаража, чей это счёт или выплата (у гаражных сделки нет). */
    public function garageCar(): HasOne
    {
        return $this->hasOne(GarageCar::class, 'invoice_id');
    }

    public function garagePayoutCar(): HasOne
    {
        return $this->hasOne(GarageCar::class, 'payout_invoice_id');
    }

    /** Менеджер, с которым этот счёт: покупатель сделки или держатель машины гаража. */
    public function manager(): ?User
    {
        return $this->deal?->buyer ?? ($this->garageCar ?? $this->garagePayoutCar)?->manager;
    }

    public function label(): string
    {
        return $this->number ? '№ '.$this->number : ($this->external_no ? $this->external_no : $this->kind->label());
    }

    /**
     * Ставка НДС счёта — ставка продавца, записанная при выставлении (`vat_rate`). Старые счета без неё —
     * прежняя галка «С НДС» и 20 %.
     */
    public function vatRate(): ?int
    {
        return $this->vat_rate ?: ($this->vat ? self::VAT : null);
    }

    /** НДС счёта: в сумме — выделенный из итога, сверху — то, что прибавлено к строкам. */
    public function vatAmount(): float
    {
        $rate = $this->vatRate();
        if (! $rate) {
            return 0;
        }

        return $this->vat_on_top ? round($this->total - $this->net(), 2) : Vat::inside($this->total, $rate);
    }

    /** Сумма строк без НДС сверху. */
    public function net(): float
    {
        return $this->vat_on_top ? round((float) $this->charges->sum('amount'), 2) : $this->total;
    }

    /** «В том числе НДС 5 %», «НДС 5 %» (сверху) или «Без НДС». */
    public function vatLabel(): string
    {
        $rate = $this->vatRate();

        return match (true) {
            ! $rate => 'Без НДС',
            (bool) $this->vat_on_top => 'НДС '.$rate.' %',
            default => 'В том числе НДС '.$rate.' %',
        };
    }
}
