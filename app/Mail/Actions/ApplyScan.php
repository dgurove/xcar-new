<?php

namespace App\Mail\Actions;

use App\Mail\Candidate;
use App\Mail\Chains\ChainBuilder;

/**
 * Выбор человека в «✨ Распознать» (`ScanFields::chosen`) — в цепочку: `chosen` поверх писем, свёртка заново.
 * Новое письмо выбор не перебивает (`ChainBuilder::fold` кладёт `chosen` первым).
 */
final class ApplyScan
{
    public function __construct(private ChainBuilder $chains) {}

    /** @param array<string, array{value: mixed, source: string}> $chosen */
    public function __invoke(Candidate $candidate, array $chosen): void
    {
        if (! $chosen) {
            return;
        }
        $candidate->forceFill(['chosen' => array_merge($candidate->chosen ?? [], $chosen)])->saveQuietly();
        $this->chains->fold($candidate);
    }
}
