<?php

namespace App\Notifications;

use App\Offers\Interest;
use App\Support\Surface;

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
        return Surface::Crm->url("/predlozheniya/{$this->interest->offer->number}");
    }

    public function offerNumber(): ?int
    {
        return $this->interest->offer->number;
    }

    public function category(): string
    {
        return 'interest';
    }
}
