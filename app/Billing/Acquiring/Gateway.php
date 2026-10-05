<?php

namespace App\Billing\Acquiring;

/** Провайдер эквайринга: ЮKassa сейчас; свой шлюз Сбера (register.do) встал бы вторым классом. */
interface Gateway
{
    public function name(): string;

    public function configured(): bool;

    public function create(PayLink $link, float $amount, string $returnUrl): Checkout;

    public function fetch(string $id): Checkout;

    /** @return array{id: string, status: string} возврат у провайдера; «pending» — ещё не вернули */
    public function refund(AcquiringPayment $attempt, float $amount): array;

    /** @return array{id: string, status: string, payment_id: string} */
    public function fetchRefund(string $id): array;
}
