<?php

namespace App\Billing\Acquiring;

/** Платёж провайдера, приведённый к нашему виду: что вернули create и fetch. */
final readonly class Checkout
{
    public function __construct(
        public string $id,
        public string $status,
        public float $amount,
        public ?float $income,
        public ?string $method,
        public ?string $url,
        public ?string $receipt,
        public array $raw,
    ) {}
}
