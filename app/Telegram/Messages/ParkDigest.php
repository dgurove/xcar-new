<?php

namespace App\Telegram\Messages;

use App\Support\Surface;

/** Утро на стоянке: сколько просрочено, забрать, принять, выдать, стоят долго. */
final class ParkDigest extends Message
{
    /** @param array<string, int> $counts */
    public function __construct(private array $counts) {}

    protected function title(): string
    {
        return 'Стоянка сегодня';
    }

    protected function lines(): array
    {
        return array_map(fn ($label, $n) => $n ? $label.': '.$n : null, array_keys($this->counts), $this->counts);
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'Заявки', 'url' => Surface::Park->url('/')];
    }
}
