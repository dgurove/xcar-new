<?php

namespace App\Notifications;

use App\Workflow\Requirement;

/** Менеджеру: сделка ждёт его ответа. */
final class YourTurnNotice extends Notice
{
    public function __construct(private Requirement $requirement) {}

    public function title(): string
    {
        return $this->requirement->title.' — № '.$this->requirement->offer->number;
    }

    public function text(): ?string
    {
        $due = $this->requirement->due_at ? ' До '.$this->requirement->due_at->translatedFormat('j M, H:i').'.' : '';

        return trim(($this->requirement->text ?? '').$due) ?: null;
    }

    public function href(): string
    {
        return "/account/deals/{$this->requirement->deal_id}";
    }

    public function offerNumber(): ?int
    {
        return $this->requirement->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function critical(): bool
    {
        return true;
    }
}
