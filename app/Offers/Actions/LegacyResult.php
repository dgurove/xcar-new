<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Support\Plural;

/** Итог вставки списка «Из таблицы Кати»: сколько перенесено и не заведено, что уже в сделке или в продаже. */
class LegacyResult
{
    public int $moved = 0;

    public int $ghosts = 0;

    public int $already = 0;

    /** @var list<Offer> */
    public array $deals = [];

    /** @var list<Offer> */
    public array $published = [];

    /** «Черновиков 10, не заведены 98, в сделке 10». */
    public function summary(): string
    {
        $parts = array_filter([
            $this->moved ? 'черновиков '.$this->moved : null,
            $this->ghosts ? 'не '.Plural::of($this->ghosts, ['заведён', 'заведены', 'заведены']).' '.$this->ghosts : null,
            $this->deals ? 'в сделке '.count($this->deals) : null,
            $this->published ? 'в продаже '.count($this->published) : null,
            $this->already ? 'уже в списке '.$this->already : null,
        ]);

        return $parts ? mb_ucfirst(implode(', ', $parts)) : 'Номеров не нашли';
    }
}
