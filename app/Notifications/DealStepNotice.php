<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Workflow\Stage;

/** Менеджеру: сделка перешла на новый шаг, ответа не ждём. */
final class DealStepNotice extends Notice
{
    public function __construct(private Deal $deal, private Stage $stage) {}

    public function title(): string
    {
        return 'Сделка № '.$this->deal->offer->number.': '.$this->stage->managerTitle();
    }

    public function text(): ?string
    {
        return $this->stage->managerText();
    }

    public function href(): string
    {
        return "/lk/sdelki/{$this->deal->id}";
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
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
