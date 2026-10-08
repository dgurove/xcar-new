<?php

namespace App\Notifications;

use App\Offers\Interest;
use App\Support\Surface;
use App\Users\User;

final class InterestNotice extends Notice
{
    public function __construct(private Interest $interest) {}

    public function title(): string
    {
        return 'Интерес к № '.$this->interest->offer->number.' — '.$this->interest->user->name;
    }

    public function text(): ?string
    {
        return $this->interest->offer->titleWithYear().($this->interest->comment ? '. '.$this->interest->comment : '');
    }

    public function href(): string
    {
        // Сотрудникам: в CRM, в редактор — на сайте тот же путь вёл на витрину.
        return Surface::Crm->url("/offers/{$this->interest->offer->number}");
    }

    public function offerNumber(): ?int
    {
        return $this->interest->offer->number;
    }

    public function actor(): ?User
    {
        return $this->interest->user;
    }

    public function category(): string
    {
        return 'interest';
    }
}
