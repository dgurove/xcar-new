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
        public ?string $cancelReason = null,
    ) {}

    /** Наша ссылка, к которой ЮKassa отнесла платёж (`metadata.link`): по ней находится платёж, которого у нас нет. */
    public function linkId(): ?int
    {
        $id = data_get($this->raw, 'metadata.link');

        return ctype_digit((string) $id) ? (int) $id : null;
    }
}
