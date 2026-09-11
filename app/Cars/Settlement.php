<?php

namespace App\Cars;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'type', 'region_code', 'is_federal_city'])]
class Settlement extends Model
{
    public function title(): string
    {
        return $this->name;
    }
}
