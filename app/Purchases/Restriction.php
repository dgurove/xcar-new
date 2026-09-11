<?php

namespace App\Purchases;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Какие категории человеку не показывать. Нет строки — видит всё. */
#[Fillable(['user_id', 'hidden_kinds'])]
class Restriction extends Model
{
    protected $table = 'purchase_restrictions';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['hidden_kinds' => 'array'];
    }

    /** @return list<string> */
    public static function hiddenFor(?User $user): array
    {
        return $user ? (self::find($user->id)?->hidden_kinds ?? []) : [];
    }
}
