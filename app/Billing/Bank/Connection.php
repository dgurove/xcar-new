<?php

namespace App\Billing\Bank;

use App\Users\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Подключение к банку — одна строка на провайдера. Токены и бессрочный client_secret шифруются:
 * refresh-токен Сбер меняет при каждом обновлении, поэтому он живёт в базе, а не в .env.
 */
#[Fillable(['provider', 'account', 'client_secret', 'secret_rotated_at', 'access_token', 'refresh_token', 'access_expires_at', 'refresh_expires_at', 'state',
    'synced_at', 'last_error', 'failed_at', 'connected_by', 'connected_at'])]
class Connection extends Model
{
    protected $table = 'billing_bank_connections';

    protected $hidden = ['client_secret', 'access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted', 'access_token' => 'encrypted', 'refresh_token' => 'encrypted',
            'secret_rotated_at' => 'datetime', 'access_expires_at' => 'datetime', 'refresh_expires_at' => 'datetime',
            'synced_at' => 'datetime', 'failed_at' => 'datetime', 'connected_at' => 'datetime',
        ];
    }

    public static function sber(): self
    {
        return self::firstOrCreate(['provider' => 'sber']);
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function connected(): bool
    {
        return filled($this->refresh_token) && $this->refresh_expires_at?->isFuture();
    }
}
