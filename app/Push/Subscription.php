<?php

namespace App\Push;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'endpoint', 'p256dh', 'auth', 'agent', 'host', 'failed_at', 'last_sent_at', 'confirmed_at', 'silent_at'])]
class Subscription extends Model
{
    protected $table = 'push_subscriptions';

    protected function casts(): array
    {
        return ['failed_at' => 'datetime', 'last_sent_at' => 'datetime', 'confirmed_at' => 'datetime', 'silent_at' => 'datetime'];
    }

    /** Устройство словами: «Chrome на Mac» (`Device::label`). */
    public function device(): string
    {
        return Device::label((string) $this->agent);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
