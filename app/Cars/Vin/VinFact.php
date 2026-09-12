<?php

namespace App\Cars\Vin;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Одна запись памяти: VIN и то, что о машине записал человек. */
#[Fillable(['vin', 'prefix', 'source_type', 'source_id', 'brand_id', 'model_id', 'year', 'transmission', 'drive', 'fuel', 'body', 'engine_volume', 'engine_power'])]
class VinFact extends Model
{
    /** Поля, которым память учится у машины. */
    public const FIELDS = ['brand_id', 'model_id', 'year', 'transmission', 'drive', 'fuel', 'body', 'engine_volume', 'engine_power'];
}
