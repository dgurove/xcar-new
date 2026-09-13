<?php

namespace App\Purchases;

/** Закупка на витрине: одна группа, свой счёт, своё имя. */
final readonly class PurchaseCard
{
    public function __construct(public Purchase $purchase, public Group $group, public int $cars, public int $rated) {}

    public function title(): string
    {
        return $this->purchase->publicTitle($this->group);
    }

    public function url(): string
    {
        return "/zakupki/{$this->purchase->number}?group={$this->group->value}";
    }
}
