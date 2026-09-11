<?php

namespace App\Offers;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'offer_id'])]
class Favorite extends Model
{
    public $timestamps = false;
}
