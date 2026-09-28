<?php

namespace App\Billing\Bank;

use App\Billing\Invoice;
use App\Billing\Payment;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Операция по расчётному счёту из выписки. Входящая узнаётся по номеру счёта в назначении и становится
 * оплатой (`matched`); не узнанная ждёт сотрудника (`unmatched`): привязать к счёту или «не наше» (`ignored`).
 * Исходящие хранятся для полноты и ничего не двигают (`outgoing`).
 */
#[Fillable(['external_id', 'account', 'booked_at', 'direction', 'amount', 'counterparty', 'counterparty_inn', 'counterparty_account', 'counterparty_bank', 'doc_number', 'purpose',
    'state', 'note', 'invoice_id', 'payment_id', 'decided_by', 'decided_at', 'payload'])]
class Transaction extends Model
{
    public const MATCHED = 'matched';

    public const UNMATCHED = 'unmatched';

    public const IGNORED = 'ignored';

    public const OUTGOING = 'outgoing';

    protected $table = 'billing_bank_transactions';

    protected function casts(): array
    {
        return ['booked_at' => 'date', 'amount' => 'float', 'decided_at' => 'datetime', 'payload' => 'array'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isIncoming(): bool
    {
        return $this->direction === 'in';
    }

    public function stateLabel(): string
    {
        return match ($this->state) {
            self::MATCHED => 'Привязано',
            self::UNMATCHED => 'Не привязано',
            self::IGNORED => 'Не наше',
            default => 'Списание',
        };
    }

    public function tone(): ?string
    {
        return match ($this->state) {
            self::MATCHED => 'open',
            self::UNMATCHED => 'urgent',
            default => 'closed',
        };
    }
}
