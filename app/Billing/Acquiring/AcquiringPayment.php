<?php

namespace App\Billing\Acquiring;

use App\Billing\Bank\Transaction;
use App\Billing\Payment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Попытка оплаты у провайдера по нашей ссылке: его id, статус, чем платили, сколько дошло до нас
 * (`income_amount` — за вычетом комиссии). Успешная связана с оплатой счёта на `applied`; всё сверх
 * (счёт закрыли раньше иначе, целиком или частью) — переплата, её возвращают из CRM.
 */
#[Fillable(['link_id', 'provider', 'external_id', 'status', 'amount', 'income_amount', 'method', 'confirmation_url', 'receipt_status', 'applied', 'refunded', 'payment_id', 'payout_tx_id', 'payload', 'checked_at'])]
class AcquiringPayment extends Model
{
    protected $table = 'billing_acquiring_payments';

    /** Сколько живёт страница оплаты у провайдера, прежде чем «Оплатить» заведёт новую попытку. */
    public const FRESH_MINUTES = 30;

    protected function casts(): array
    {
        return ['amount' => 'float', 'income_amount' => 'float', 'applied' => 'float', 'refunded' => 'float', 'payload' => 'array', 'checked_at' => 'datetime'];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(PayLink::class, 'link_id');
    }

    /** Перечисление ЮMoney на расчётный счёт, в которое вошла оплата (`ReconcilePayout`). */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'payout_tx_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'waiting_for_capture'], true);
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    /** Комиссия провайдера: сколько не дошло. */
    public function fee(): ?float
    {
        return $this->income_amount === null ? null : round($this->amount - $this->income_amount, 2);
    }

    /** Заплачено сверх того, что легло в счёт, и ещё не возвращено. */
    public function overpaid(): float
    {
        return $this->succeeded() ? max(0, round($this->amount - $this->applied - $this->refunded, 2)) : 0;
    }

    public function refundable(): float
    {
        return $this->succeeded() ? max(0, round($this->amount - $this->refunded, 2)) : 0;
    }
}
