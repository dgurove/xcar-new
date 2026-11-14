<?php

namespace App\Billing\Bank;

use App\Billing\Invoice;
use App\Billing\Payment;
use App\Offers\Deal;
use App\Search\Term;
use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Операция по расчётному счёту из выписки. Входящая узнаётся по номеру счёта в назначении и становится
 * оплатой (`matched`); не узнанная ждёт сотрудника (`unmatched`): привязать к счёту или «не наше» (`ignored`).
 * Исходящие — `outgoing`. И те и другие привязываются к машине по назначению (`CarMatch` → `deal_id`): по ним «Продано» и
 * «Оплачено» сделки (`StampDealMoney`, 14.11.2026).
 */
#[Fillable(['external_id', 'account', 'booked_at', 'direction', 'amount', 'counterparty', 'counterparty_inn', 'counterparty_account', 'counterparty_bank', 'doc_number', 'purpose',
    'state', 'note', 'invoice_id', 'payment_id', 'decided_by', 'decided_at', 'payload', 'deal_id', 'matched_by'])]
class Transaction extends Model
{
    use FastAttributes;

    public const MATCHED = 'matched';

    public const UNMATCHED = 'unmatched';

    public const IGNORED = 'ignored';

    public const OUTGOING = 'outgoing';

    /** Выписка за прошлое (`bank:history`): только для дат сделок, счетов и оплат не трогает. */
    public const HISTORY = 'history';

    /** Раньше этого дня строку истории храним, только если она про нашу машину. */
    public const HISTORY_KEEP_FROM = '2026-06-01';

    protected $table = 'billing_bank_transactions';

    protected function casts(): array
    {
        return ['booked_at' => 'date', 'amount' => 'float', 'decided_at' => 'datetime', 'payload' => 'array'];
    }

    /** Лупа выписки: плательщик, назначение, ИНН целиком. */
    public function scopeSearch(Builder $q, string|Term $term): Builder
    {
        $t = Term::of($term);

        return $t->blank() ? $q : $q->where(fn ($s) => $s->where('counterparty', 'ilike', Term::like($t->raw))
            ->orWhere('purpose', 'ilike', Term::like($t->raw))->orWhere('counterparty_inn', $t->raw));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
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
            self::HISTORY => 'Прошлое',
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
