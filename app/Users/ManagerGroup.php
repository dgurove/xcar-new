<?php

namespace App\Users;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Группа менеджеров для показа предложений: «Свои», «Москва», «Новые». Менеджер может быть в нескольких. */
#[Fillable(['name', 'position'])]
class ManagerGroup extends Model
{
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'manager_group_user', 'group_id', 'user_id')->orderBy('name');
    }
}
