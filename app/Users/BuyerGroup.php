<?php

namespace App\Users;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Группа покупателей одного менеджера: имя даёт он сам, состав меняет когда хочет. */
#[Fillable(['manager_id', 'name', 'position'])]
class BuyerGroup extends Model
{
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'buyer_group_user', 'group_id', 'user_id')->withPivot('created_at')->orderBy('name');
    }

    public function showings(): HasMany
    {
        return $this->hasMany(\App\Offers\Showing::class, 'group_id');
    }
}
