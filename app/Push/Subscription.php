<?php

namespace App\Push;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'endpoint', 'p256dh', 'auth', 'agent', 'host', 'failed_at'])]
class Subscription extends Model
{
    protected $table = 'push_subscriptions';

    protected function casts(): array
    {
        return ['failed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
