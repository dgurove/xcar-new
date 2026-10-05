<?php

namespace App\Billing;

use App\Billing\Acquiring\PayLink;
use App\Billing\Acquiring\PayLinkState;
use App\Offers\Deal;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Support\Demo\HidesDemo;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Счёт. `issued` — нам должны, `owed` — должны мы (перечисление вендору по
 * договору комиссии). Остаток и просрочка считаются, не хранятся; PDF — снимок
 * в момент выставления, коллекция `file` на закрытом диске. `seller` — кто из нас
 * выставил: реквизиты в документах и свой ряд номеров.
 */
#[Fillable(['seller', 'direction', 'year', 'number', 'external_no', 'kind', 'party_id', 'vehicle_id', 'deal_id', 'offer_id', 'issued_at', 'due_at', 'vat', 'vat_rate', 'vat_on_top', 'total', 'paid', 'state', 'paid_at',
    'overdue_at', 'reminded_at', 'sent_at', 'voided_at', 'void_reason', 'notes', 'created_by'])]
class Invoice extends Model implements HasMedia
{
    use HidesDemo;
    use InteractsWithMedia;

    protected $table = 'billing_invoices';

    public const VAT = 20;

    protected function casts(): array
    {
        return ['seller' => Seller::class, 'kind' => ChargeKind::class, 'state' => InvoiceState::class, 'issued_at' => 'date', 'due_at' => 'date', 'paid_at' => 'date', 'vat' => 'bool', 'vat_rate' => 'int', 'vat_on_top' => 'bool',
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

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
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

    /** Ссылки на оплату; открытая у счёта одна. */
    public function payLinks(): HasMany
    {
        return $this->hasMany(PayLink::class, 'invoice_id')->orderBy('id');
    }

    public function openLink(): ?PayLink
    {
        return $this->relationLoaded('payLinks') ? $this->payLinks->first->isOpen() : $this->payLinks()->where('state', PayLinkState::Open)->first();
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

    public function remaining(): float
    {
        return $this->state === InvoiceState::Void ? 0 : max(0, round($this->total - $this->paid, 2));
    }

    public function isOverdue(): bool
    {
        return $this->state === InvoiceState::Issued && $this->remaining() > 0 && $this->due_at->isPast() && ! $this->due_at->isToday();
    }

    /** Сколько полных дней прошло после срока. */
    public function overdueDays(): int
    {
        return (int) $this->due_at->startOfDay()->diffInDays(now()->startOfDay());
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
            $this->due_at->lte(now()->addDays(3)) => 'urgent',
            default => null,
        };
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
