<?php

namespace App\Billing\Acquiring;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Payment;
use App\Billing\Seller;
use App\Garage\Car;
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
#[Fillable(['code', 'invoice_id', 'amount', 'payer_kind', 'payer_user_id', 'payer_name', 'payer_phone', 'payer_email', 'state', 'payment_id', 'paid_at', 'canceled_at', 'error', 'error_at', 'created_by'])]
class PayLink extends Model
{
    protected $table = 'billing_pay_links';

    /** Без 0/O, 1/l/I: ссылку диктуют по телефону. */
    private const ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';

    protected function casts(): array
    {
        return ['amount' => 'float', 'payer_kind' => PayerKind::class, 'state' => PayLinkState::class, 'paid_at' => 'datetime', 'canceled_at' => 'datetime', 'error_at' => 'datetime'];
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

    /**
     * Счёт, который платят по ссылке: наш (ПРАЙМ) к оплате по сделке или гаражу, шлюз подключён. Парковка (ИП),
     * вознаграждение от вендора, наши обязательства и демо — нет. Одна дверь для `EnsurePayLink` и экранов.
     */
    public static function eligible(Invoice $invoice): bool
    {
        return $invoice->state === InvoiceState::Issued && ! $invoice->isOwed() && ! $invoice->is_demo
            && $invoice->seller === Seller::Prime && $invoice->kind !== ChargeKind::Reward
            // Счёт ПРАЙМ за машину по сделке платят по счёту, без ссылки (Совкомбанк, 05.10.2026); ссылка — у подбора и доли.
            && ! ($invoice->deal_id && $invoice->kind === ChargeKind::Sale)
            && ($invoice->deal_id || $invoice->isService() || Car::ofInvoice($invoice)) && app(Gateway::class)->configured();
    }

    /** Адрес без схемы — так его читают и диктуют: «xcar.ru/pay/k3m…». */
    public function shortUrl(): string
    {
        return preg_replace('~^https?://~', '', $this->url());
    }

    /**
     * Что сейчас со ссылкой — одной строкой и тоном, для сотрудника и менеджера одинаково (05.10.2026: «непонятно,
     * оплатили или нет»): по последней попытке видно, открывал ли плательщик оплату и чем кончилось.
     *
     * @return array{string, ?string}
     */
    public function stateLine(): array
    {
        if ($this->state === PayLinkState::Paid) {
            return ['оплачено '.$this->paid_at->translatedFormat('j M'), 'open'];
        }
        if ($this->state === PayLinkState::Canceled) {
            return ['отменена', 'muted'];
        }
        $last = $this->relationLoaded('attempts') ? $this->attempts->last() : $this->attempts()->reorder()->latest('id')->first();
        $at = fn ($t) => $t->translatedFormat('j M, H:i');

        return match (true) {
            $this->error_at && (! $last || $this->error_at->gt($last->created_at)) => ['ЮKassa не открыла оплату '.$at($this->error_at), 'danger'],
            ! $last => ['ждём оплату, ещё не открывали', null],
            $last->isPending() => ['открыли оплату '.$at($last->created_at).', ждём', 'urgent'],
            $last->status === 'canceled' && $last->cancel_reason === 'expired_on_confirmation' => ['открывали '.$at($last->created_at).', не оплатили', 'urgent'],
            $last->status === 'canceled' => ['не прошла '.$at($last->created_at).': '.$last->cancelLabel(), 'danger'],
            default => ['ждём оплату', null],
        };
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
