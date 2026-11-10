<?php

namespace App\Telegram\Messages;

use App\Support\Surface;

/** Медленные ответы за день (`perf:digest`) — только владельцу. */
final class SlowDigest extends Message
{
    public function __construct(private string $day, private array $facts) {}

    public function ownerOnly(): bool
    {
        return true;
    }

    protected function title(): string
    {
        return 'Медленно за '.$this->day;
    }

    protected function lines(): array
    {
        return $this->facts;
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'Открыть CRM', 'url' => Surface::Crm->url('/')];
    }
}
