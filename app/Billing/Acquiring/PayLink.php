<?php

namespace App\Billing\Acquiring;

use App\Billing\Invoice;
use App\Billing\Payment;
use App\Support\Surface;
use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ссылка на оплату счёта: наша, постоянная (`/pay/{code}`), отдаётся плательщику как есть. За ней —
 * попытки у провайдера (`AcquiringPayment`): каждое «Оплатить» берёт свежую или заводит новую, так что
 * ссылка не протухает вместе со страницей оплаты. У счёта открыта одна ссылка.
 */
#[Fillable(['code', 'invoice_id', 'amount', 'payer_kind', 'payer_user_id', 'payer_name', 'payer_phone', 'payer_email', 'state', 'payment_id', 'paid_at', 'canceled_at', 'created_by'])]
class PayLink extends Model
{
    protected $table = 'billing_pay_links';

    /** Без 0/O, 1/l/I: ссылку диктуют по телефону. */
    private const ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';

    protected function casts(): array
    {
        return ['amount' => 'float', 'payer_kind' => PayerKind::class, 'state' => PayLinkState::class, 'paid_at' => 'datetime', 'canceled_at' => 'datetime'];
    }

    public static function freshCode(): string
    {
        do {
            $code = '';
            foreach (str_split(random_bytes(12)) as $byte) {
                $code .= self::ALPHABET[ord($byte) % strlen(self::ALPHABET)];
            }
        } while (self::where('code', $code)->exists());

        return $code;
    }

    /** Страница оплаты открывается и без входа — демо-счёт по ссылке тоже виден. */
    /** Сумма ссылки по умолчанию: остаток к оплате, но не больше лимита одного платежа. */
    public static function defaultAmount(Invoice $invoice): float
    {
        $left = max(0, round($invoice->remaining() - $invoice->claimed(), 2));
        $max = (float) config('xcar.yookassa.max_amount');

        return $max > 0 ? min($left, $max) : $left;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope('demo');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function payerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(AcquiringPayment::class, 'link_id')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->state === PayLinkState::Open;
    }

    public function url(): string
    {
        return Surface::Site->url('/pay/'.$this->code);
    }

    /** Кто платит — словами для строки: «вы», имя покупателя или человека. */
    public function payerLabel(?User $viewer = null): string
    {
        if ($this->payer_kind === PayerKind::Self) {
            return $viewer && $viewer->id === $this->created_by ? 'вы' : ($this->creator?->name ?? 'менеджер');
        }

        return $this->payer_name ?: $this->payerUser?->name ?: 'покупатель';
    }
}
