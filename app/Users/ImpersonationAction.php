<?php

namespace App\Users;

use App\Support\FastAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Запись, сделанная админом за человека: метод, путь, код ответа. */
#[Fillable(['impersonation_id', 'method', 'path', 'status', 'created_at'])]
class ImpersonationAction extends Model
{
    use FastAttributes;

    public const UPDATED_AT = null;
}
