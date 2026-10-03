<?php

namespace App\Telegram\Offers;

use App\Offers\Offer;
use App\Users\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Подписчик бота предложений и где он в разговоре (`mode`). Пишется часто и без ценности при аварии — через
 * `pgsql_async`: на HDD прода обычная фиксация стоит 160–290 мс, а бот пишет на каждое нажатие. Таблица — только
 * этим соединением, иначе процесс заблокирует сам себя.
 */
final class Subscriber extends Model
{
    public const MENU = 'menu';

    public const PROMPT = 'prompt';

    public const FEED = 'feed';

    public const ASKING = 'asking';

    public const INVITE = 'invite';

    protected $connection = 'pgsql_async';

    protected $table = 'offer_bot_chats';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'muted_at' => 'datetime',
            'blocked_at' => 'datetime',
            'remind_at' => 'datetime',
            'announced_at' => 'datetime',
            'morning_on' => 'date',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** Бот может ему писать: не остановил. */
    public function scopeReachable(Builder $q): Builder
    {
        return $q->whereNull('blocked_at');
    }

    /** Получает рассылки 13:00 и 16:00: не отписался и не остановил. */
    public function scopeListening(Builder $q): Builder
    {
        return $q->whereNull('blocked_at')->whereNull('muted_at');
    }

    public function isSubscribed(): bool
    {
        return $this->muted_at === null;
    }

    public static function of(User $user): ?self
    {
        return self::where('user_id', $user->id)->first();
    }

    public function moveTo(string $mode, ?int $offerId = null): void
    {
        $this->forceFill(['mode' => $mode, 'offer_id' => $offerId])->save();
    }
}
