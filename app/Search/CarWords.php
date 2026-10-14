<?php

namespace App\Search;

use Illuminate\Database\Eloquent\Builder;

/**
 * Слова запроса про машину — одно правило на все списки и шапку: каждое слово должно найтись хоть в чём-то (марка и
 * модель по имени и русскому имени — «рио», «крета», год, кусок VIN, и что добавит сам список), слова между собой — И. Раньше «марка ilike» было
 * написано около двенадцати раз тремя способами, и «Kia Rio» не находилось нигде: фраза сравнивалась целиком.
 */
final class CarWords
{
    /**
     * Слова ищутся в машине дорожки (`Cars\Car`: марка, модель, год, VIN) — у предложения, ТС парковки и лота закупки одна
     * и та же запись.
     *
     * @param  Builder  $q  запрос дорожки со связью `car`
     * @param  null|callable(Builder, string, string): void  $more  ещё места для слова (`$q`, слово, `like` слова)
     * @param  null|callable(Builder): void  $vinOpen  где кусок VIN искать можно; null — везде (CRM, парковка)
     */
    public static function apply(Builder $q, Term $t, ?callable $more = null, bool $vin = true, ?callable $vinOpen = null): Builder
    {
        foreach ($t->words as $word) {
            $like = Term::like($word);
            $q->where(function ($one) use ($word, $like, $more, $vin, $vinOpen) {
                $one->whereHas('car', fn ($c) => $c->where(fn ($w) => $w->whereHas('brand', fn ($b) => $b->search($word))
                    ->orWhereHas('model', fn ($m) => $m->search($word))
                    ->orWhereRaw('cast(cars.year as text) = ?', [$word])))
                    // Кусок VIN любой длины: последние четыре знака, начало без цифр. Где VIN скрыт — нет: подбором куска по
                    // знаку его восстановили бы целиком (13.10.2026).
                    ->when($vin, fn ($v) => $v->orWhere(fn ($x) => $x->whereHas('car', fn ($c) => $c->whereRaw('lower(cars.vin) like ?', [$like]))->when($vinOpen, $vinOpen)));
                if ($more) {
                    $more($one, $word, $like);
                }
            });
        }

        return $q;
    }
}
