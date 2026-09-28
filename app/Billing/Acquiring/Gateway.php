<?php

namespace App\Billing\Acquiring;

/** Провайдер эквайринга: ЮKassa сейчас; свой шлюз Сбера (register.do) встал бы вторым классом. */
interface Gateway
{
    public function name(): string;

    public function configured(): bool;

    public function create(PayLink $link, float $amount, string $returnUrl): Checkout;

    public function fetch(string $id): Checkout;

    public function refund(AcquiringPayment $attempt, float $amount): void;
}
