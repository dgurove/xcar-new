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
     * @param  string  $table  таблица с `brand_id`, `model_id`, `year`
     * @param  null|callable(Builder, string, string): void  $more  ещё места для слова (`$q`, слово, `like` слова)
     * @param  null|callable(Builder): void  $vinOpen  где кусок VIN искать можно; null — везде (CRM, парковка)
     */
    public static function apply(Builder $q, Term $t, string $table, ?callable $more = null, bool $vin = true, ?callable $vinOpen = null): Builder
    {
        foreach ($t->words as $word) {
            $like = Term::like($word);
            $q->where(function ($one) use ($word, $like, $table, $more, $vin, $vinOpen) {
                $one->whereHas('brand', fn ($b) => $b->search($word))
                    ->orWhereHas('model', fn ($m) => $m->search($word))
                    ->orWhereRaw("cast({$table}.year as text) = ?", [$word])
                    // Кусок VIN любой длины: последние четыре знака, начало без цифр. Где VIN скрыт — нет: подбором куска по
                    // знаку его восстановили бы целиком (13.10.2026).
                    ->when($vin, fn ($v) => $v->orWhere(fn ($x) => $x->whereRaw("lower({$table}.vin) like ?", [$like])->when($vinOpen, $vinOpen)));
                if ($more) {
                    $more($one, $word, $like);
                }
            });
        }

        return $q;
    }
}
