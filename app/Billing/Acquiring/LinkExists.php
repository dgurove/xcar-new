<?php

namespace App\Billing\Acquiring;

use RuntimeException;

/** Ссылка на оплату по этой машине или этому плательщику уже открыта — вторую не заводим, ведём к ней. */
final class LinkExists extends RuntimeException
{
    public function __construct(public readonly PayLink $link)
    {
        parent::__construct('Ссылка уже есть');
    }
}
