<?php

namespace App\Offers;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Метка на оффере: название и цвет. Сами метки лежат в offers.tags списком названий. */
#[Fillable(['name', 'color', 'sort'])]
class Tag extends Model
{
    public const COLORS = ['lime' => 'Лайм', 'orange' => 'Оранжевый', 'red' => 'Красный', 'blue' => 'Синий', 'grey' => 'Серый'];
}
