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
#[Fillable(['link_id', 'provider', 'external_id', 'status', 'cancel_reason', 'refund_id', 'refund_status', 'amount', 'income_amount', 'method', 'confirmation_url', 'receipt_status', 'applied', 'refunded', 'payment_id', 'payout_tx_id', 'payload', 'checked_at'])]
class AcquiringPayment extends Model
{
    protected $table = 'billing_acquiring_payments';

    /** Сколько живёт страница оплаты у провайдера, прежде чем «Оплатить» заведёт новую попытку. */
    public const FRESH_MINUTES = 30;

    protected function casts(): array
    {
        return ['amount' => 'float', 'income_amount' => 'float', 'applied' => 'float', 'refunded' => 'float', 'payload' => 'array', 'checked_at' => 'datetime'];
    }

    /**
     * Попытка по платежу провайдера — та же строка, если он у нас уже есть. Повтор создания с тем же ключом
     * идемпотентности возвращает тот же платёж, а платёж, ответ о котором потерялся, приходит уведомлением: обоих
     * узнаём по id, второго — по `metadata.link`. Чужой платёж (ссылки нет) — null.
     */
    public static function adopt(Checkout $c, string $provider): ?self
    {
        $link = $c->linkId() ? PayLink::find($c->linkId()) : null;
        if (! $link) {
            return self::where('external_id', $c->id)->first();
        }

        return self::firstOrCreate(['external_id' => $c->id], [
            'link_id' => $link->id, 'provider' => $provider, 'status' => $c->status, 'cancel_reason' => $c->cancelReason, 'amount' => $c->amount,
            'confirmation_url' => $c->url, 'payload' => $c->raw, 'checked_at' => now(),
        ]);
    }

    /** Почему не прошла — словами для плательщика и менеджера (`cancellation_details.reason` ЮKassa). */
    public function cancelLabel(): ?string
    {
        return match ($this->cancel_reason) {
            null => null,
            'insufficient_funds' => 'недостаточно денег',
            'expired_on_confirmation' => 'не оплатил вовремя',
            'card_expired' => 'срок карты истёк',
            'payment_method_limit_exceeded' => 'лимит по карте',
            'payment_method_restricted', 'issuer_unavailable', 'country_forbidden', 'fraud_suspected', 'permission_revoked' => 'банк отклонил',
            'call_issuer' => 'банк просит позвонить ему',
            '3d_secure_failed' => 'не прошла проверка банка',
            'canceled_by_merchant' => 'отменили мы',
            default => 'отклонено',
        };
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
