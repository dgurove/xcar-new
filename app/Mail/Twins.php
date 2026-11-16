<?php

namespace App\Mail;

use Illuminate\Support\Collection;

/**
 * Одно и то же письмо в нескольких ветках (16.11.2026, Skoda Rapid 0770/046/01145/26: Ткаченко одним текстом ответил в ч.1 и
 * ч.3 почти в одну секунду — на парковке два одинаковых блока «Ждут ответа»). Двойники — один отправитель и те же слова
 * (своя часть письма, без цитаты), в пределах суток. Блок один — на самое новое; «Ответ не нужен» закрывает все ветки
 * (`Park\VehicleController::letterDone`), ответ на одно закрывает остальные сам (`Threads::answeredElsewhere`).
 */
final class Twins
{
    public static function key(Message $m): string
    {
        return mb_strtolower((string) $m->from_email).'|'.mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($m->ownText())));
    }

    /** Ждущие ответа письма без двойников: из каждой группы — самое новое, у него `twins` — сколько веток. */
    public static function collapse(Collection $asks): Collection
    {
        $out = [];
        foreach ($asks->sortBy(fn (Message $m) => [$m->date_at, $m->id]) as $m) {
            $twin = collect($out)->search(fn (Message $o) => self::key($o) === self::key($m) && abs($o->date_at->diffInHours($m->date_at)) <= 24);
            if ($twin === false) {
                $m->twins = 1;
                $out[] = $m;

                continue;
            }
            $m->twins = $out[$twin]->twins + 1;
            $m->twinIds = [...($out[$twin]->twinIds ?? [$out[$twin]->id]), $m->id];
            $out[$twin] = $m;
        }

        return collect($out)->sortBy('date_at')->values();
    }

    /** Двойники письма среди ждущих ответа писем тех же веток (сам `$m` тоже). */
    public static function of(Message $m, Collection $candidates): Collection
    {
        return $candidates->filter(fn (Message $o) => self::key($o) === self::key($m) && abs($o->date_at->diffInHours($m->date_at)) <= 24)->values();
    }
}
