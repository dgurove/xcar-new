<?php

namespace App\Offers;

use App\Support\FastAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'offer_id'])]
class Favorite extends Model
{
    use FastAttributes;

    public $timestamps = false;
}
