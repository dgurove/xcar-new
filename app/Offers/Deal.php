<?php

namespace App\Offers;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Users\User;
use App\Workflow\Requirement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Сделка: чьё подтверждение принято и почём. Деньги — `cost` (закупочная в момент
 * принятия, снимок), `commission` (агентское вознаграждение менеджеру) и режим:
 * выплачиваем после оплаты или менеджер удерживает сам. Менеджер вознаграждение
 * не видит, пока по сделке нет живого счёта — `showsCommission()`.
 */
#[Fillable(['offer_id', 'bid_id', 'buyer_id', 'amount', 'cost', 'commission', 'commission_mode', 'state', 'notes', 'closed_at'])]
class Deal extends Model
{
    protected function casts(): array
    {
        return ['state' => DealState::class, 'amount' => 'int', 'cost' => 'int', 'commission' => 'int', 'commission_mode' => CommissionMode::class, 'closed_at' => 'datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function bid(): BelongsTo
    {
        return $this->belongsTo(Bid::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class)->latest();
    }

    public function openRequirement(): HasOne
    {
        return $this->hasOne(Requirement::class)->whereNull('done_at')->latestOfMany();
    }

    /** Живые счета по сделке в обе стороны, старые первыми. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->where('state', '!=', InvoiceState::Void)->orderBy('id');
    }

    /** Наши счета покупателю или вендору по этой сделке. */
    public function issuedInvoices(): HasMany
    {
        return $this->invoices()->where('direction', 'issued');
    }

    /** Обязательство перед менеджером — вознаграждение к выплате. */
    public function agentFee(): HasOne
    {
        return $this->hasOne(Invoice::class)->where('direction', 'owed')->where('kind', ChargeKind::AgentFee)->where('state', '!=', InvoiceState::Void)->latestOfMany();
    }

    public function isActive(): bool
    {
        return $this->state === DealState::Active;
    }

    /** Разница между ценой подтверждения и закупочной; без закупочной — null. */
    public function margin(): ?int
    {
        return $this->cost === null ? null : $this->amount - $this->cost;
    }

    /** Что остаётся нам после вознаграждения менеджера. */
    public function ours(): ?int
    {
        $margin = $this->margin();

        return $margin === null ? null : $margin - (int) $this->commission;
    }

    public function withholds(): bool
    {
        return $this->commission_mode === CommissionMode::Withheld;
    }

    /** Менеджеру вознаграждение открывается с первого живого счёта по сделке. */
    public function showsCommission(): bool
    {
        return $this->commission !== null && $this->invoices()->exists();
    }

    /** Вознаграждение правится, пока по сделке нет ни одного живого счёта. */
    public function commissionEditable(): bool
    {
        return ! $this->invoices()->exists();
    }

    public function commissionState(): CommissionState
    {
        if (! $this->showsCommission()) {
            return CommissionState::Hidden;
        }
        if ($this->withholds()) {
            return CommissionState::Withheld;
        }
        $fee = $this->agentFee;

        return match (true) {
            $fee === null => CommissionState::Awaiting,
            $fee->state === InvoiceState::Paid => CommissionState::Paid,
            default => CommissionState::Payable,
        };
    }

    /** Все наши счета по сделке оплачены, и хотя бы один есть — сигнал «вознаграждение к выплате». */
    public function fullyPaid(): bool
    {
        $issued = $this->issuedInvoices()->get();

        return $issued->isNotEmpty() && $issued->every(fn (Invoice $i) => $i->state === InvoiceState::Paid);
    }
}
