<?php

namespace App\Offers;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Шаблон показа: волны правил (см. AudienceRules). Предложение берёт его снимком — правка шаблона выставленные не трогает. */
#[Fillable(['name', 'position', 'rules'])]
class Audience extends Model
{
    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    public function summary(): string
    {
        return AudienceRules::summary($this->rules);
    }
}
