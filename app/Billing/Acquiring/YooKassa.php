<?php

namespace App\Billing\Acquiring;

use App\Billing\Vat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ЮKassa, Payments API v3 без SDK: платёж с `capture: true` и переходом на её страницу (карта, СБП, SberPay),
 * чек 54-ФЗ одной позицией «Подбор ТС …» на сумму платежа (услуга оказана — полный расчёт, НДС ставкой счёта) — строк
 * счёта плательщик не видит. «Чеки от ЮKassa» уходят только на почту: без неё платёж с чеком не создаётся.
 * `Idempotence-Key` создания — от тела запроса и получасового окна: обрыв или 5xx повторяются тем же ключом, и ЮKassa
 * отдаёт уже заведённый платёж, а не второй. Платёж, о котором мы так и не узнали, найдётся по `metadata.link`
 * (уведомление, `AcquiringPayment::adopt`).
 */
final class YooKassa implements Gateway
{
    public function name(): string
    {
        return 'yookassa';
    }

    public function configured(): bool
    {
        return filled(config('xcar.yookassa.shop_id')) && filled(config('xcar.yookassa.secret'));
    }

    public function create(PayLink $link, float $amount, string $returnUrl): Checkout
    {
        $invoice = $link->invoice;
        $what = 'Оплата по счёту '.$invoice->label().' от '.$invoice->issued_at->format('d.m.Y');
        $body = [
            'amount' => self::money($amount),
            'capture' => true,
            'confirmation' => ['type' => 'redirect', 'return_url' => $returnUrl],
            'description' => Str::limit($what, 125, ''),
            'metadata' => ['link' => (string) $link->id, 'invoice' => (string) $invoice->id],
        ];
        if (config('xcar.yookassa.receipt')) {
            $body['receipt'] = $this->receipt($link, $amount);
        }

        $key = 'pay-'.$link->id.'-'.sha1(json_encode($body).'|'.intdiv(time(), AcquiringPayment::FRESH_MINUTES * 60));

        return $this->checkout($this->http()->retry(3, 1500, self::transient(...))->withHeaders(['Idempotence-Key' => $key])->post('/payments', $body)->throw()->json());
    }

    public function fetch(string $id): Checkout
    {
        return $this->checkout($this->http()->get('/payments/'.rawurlencode($id))->throw()->json());
    }

    public function refund(AcquiringPayment $attempt, float $amount): array
    {
        $body = ['payment_id' => $attempt->external_id, 'amount' => self::money($amount)];
        // Полный возврат ЮKassa пробивает по чеку платежа сама; частичный (переплата) — только со своим чеком.
        $full = $attempt->refunded < 0.005 && abs($amount - $attempt->amount) < 0.005;
        if (config('xcar.yookassa.receipt') && ! $full) {
            $body['receipt'] = $this->receipt($attempt->link, $amount);
        }
        $refund = $this->http()->retry(3, 1500, self::transient(...))
            ->withHeaders(['Idempotence-Key' => 'refund-'.$attempt->id.'-'.round($attempt->refunded * 100)])->post('/refunds', $body)->throw()->json();
        if (($refund['status'] ?? null) === 'canceled') {
            throw new RuntimeException('ЮKassa отклонила возврат: '.data_get($refund, 'cancellation_details.reason', 'без причины'));
        }

        return ['id' => (string) $refund['id'], 'status' => (string) $refund['status']];
    }

    public function fetchRefund(string $id): array
    {
        $r = $this->http()->get('/refunds/'.rawurlencode($id))->throw()->json();

        return ['id' => (string) $r['id'], 'status' => (string) $r['status'], 'payment_id' => (string) ($r['payment_id'] ?? '')];
    }

    /** Повторять стоит обрыв и 5xx: с тем же ключом ЮKassa не заведёт второй платёж или возврат. */
    private static function transient(\Throwable $e): bool
    {
        return $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError());
    }

    /** Чек: покупатель — почта (обязательна) и телефон плательщика по ссылке, позиция одна. */
    private function receipt(PayLink $link, float $amount): array
    {
        $invoice = $link->invoice;
        $phone = preg_replace('/\D/', '', (string) $link->payer_phone);
        if (strlen($phone) === 11 && $phone[0] === '8') {
            $phone = '7'.substr($phone, 1);
        } elseif (strlen($phone) === 10) {
            // Набрали без кода страны — чеку нужен полный номер, иначе ЮKassa отклоняет платёж целиком.
            $phone = '7'.$phone;
        }
        $customer = array_filter([
            'full_name' => $link->payer_name ?: null,
            'phone' => strlen($phone) >= 11 ? $phone : null,
            'email' => $link->payer_email ?: null,
        ]);

        return [
            'customer' => $customer,
            'items' => [[
                'description' => Str::limit(trim('Подбор ТС '.($invoice->offer?->titleWithYear() ?? '')), 128, ''),
                'quantity' => 1,
                'amount' => self::money($amount),
                'vat_code' => Vat::receiptCode($invoice->vatRate()),
                'payment_subject' => config('xcar.yookassa.payment_subject'),
                'payment_mode' => config('xcar.yookassa.payment_mode'),
            ]],
        ];
    }

    private function checkout(array $p): Checkout
    {
        return new Checkout(
            id: (string) $p['id'],
            status: (string) $p['status'],
            amount: (float) data_get($p, 'amount.value'),
            income: data_get($p, 'income_amount.value') !== null ? (float) data_get($p, 'income_amount.value') : null,
            method: data_get($p, 'payment_method.type'),
            url: data_get($p, 'confirmation.confirmation_url'),
            receipt: data_get($p, 'receipt_registration'),
            raw: $p,
            cancelReason: data_get($p, 'cancellation_details.reason'),
        );
    }

    private function http(): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('ЮKassa не подключена: нет YOOKASSA_SHOP_ID и YOOKASSA_SECRET_KEY');
        }

        return Http::baseUrl(rtrim((string) config('xcar.yookassa.url'), '/'))
            ->withBasicAuth((string) config('xcar.yookassa.shop_id'), (string) config('xcar.yookassa.secret'))
            ->acceptJson()->asJson()->timeout(20)->connectTimeout(8);
    }

    private static function money(float $amount): array
    {
        return ['value' => number_format($amount, 2, '.', ''), 'currency' => 'RUB'];
    }
}
