<?php

namespace App\Billing;

use App\Offers\Deal;
use App\Offers\Offer;
use App\Park\Vehicle;
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
 * в момент выставления, коллекция `file` на закрытом диске.
 */
#[Fillable(['direction', 'year', 'number', 'external_no', 'kind', 'party_id', 'vehicle_id', 'deal_id', 'offer_id', 'issued_at', 'due_at', 'vat', 'total', 'paid', 'state', 'paid_at',
    'overdue_at', 'reminded_at', 'sent_at', 'voided_at', 'void_reason', 'notes', 'created_by'])]
class Invoice extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'billing_invoices';

    public const VAT = 20;

    protected function casts(): array
    {
        return ['kind' => ChargeKind::class, 'state' => InvoiceState::class, 'issued_at' => 'date', 'due_at' => 'date', 'paid_at' => 'date', 'vat' => 'bool',
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
    public function claimed(): float
    {
        return round((float) $this->claims()->sum('amount'), 2);
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
        return $this->state === InvoiceState::Issued && $this->paid > 0 && $this->remaining() > 0;
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

    /** НДС внутри суммы: «в том числе НДС 20 %». */
    public function vatAmount(): float
    {
        return $this->vat ? round($this->total * self::VAT / (100 + self::VAT), 2) : 0;
    }
}
