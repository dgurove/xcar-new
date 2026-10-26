<?php

namespace App\Offers\Intake;

use App\Cars\Car;
use App\Mail\Extraction\Code;

/**
 * Откуда заводится черновик (`OpenDraft`): вид двери, тождество машины, поля и сумма страховой.
 *
 * - `kind` — `mail`, `park`, `purchase`, `legacy`, `migtorg`, `manual`;
 * - `fields` — поля предложения как для `UpdateOffer` (вендор, город, НДС — если источник их знает);
 * - `amount` — сумма страховой неизвестного рода: цены по `Pricing::fromAmount` вендора черновика;
 * - `created` — к записи «Заведён» в истории (`mail`, `park`, `purchase` + `dl`, `legacy`, `migtorg`);
 * - `why` — к записи «Изменён» с полями источника;
 * - `reopenCancelled` — отменённое (отказ поставщика) тоже вернуть в черновики; почта — нет: письмо об отказе отказ не
 *   отменяет, оно ложится к тому, что есть.
 */
final class DraftSource
{
    public function __construct(
        public readonly string $kind,
        public readonly ?string $claimRef = null,
        public readonly ?string $vin = null,
        public readonly array $fields = [],
        public readonly ?int $amount = null,
        public readonly ?Car $car = null,
        public readonly array $created = [],
        public readonly ?string $why = null,
        public readonly bool $reopenCancelled = true,
    ) {}

    public function claimKey(): ?string
    {
        $ref = $this->claimRef ?? $this->fields['claim_ref'] ?? null;

        return $ref ? (Code::key($ref) ?: null) : null;
    }

    /** Замок на машину: два заведения одной машины (руками и почтой разом) не расходятся на два черновика. */
    public function lockKey(): ?string
    {
        return match (true) {
            (bool) $this->car => 'car:'.$this->car->id,
            (bool) $this->claimKey() => 'claim:'.$this->claimKey(),
            (bool) ($this->vin ?? $this->fields['vin'] ?? null) => 'vin:'.strtoupper($this->vin ?? $this->fields['vin']),
            default => null,
        };
    }
}
