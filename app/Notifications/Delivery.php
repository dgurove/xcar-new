<?php

namespace App\Notifications;

use App\Support\FastAttributes;
use App\Users\Impersonation;
use App\Users\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Строка журнала доставки: уведомление человеку по каналу (`feed`, `banner`, `push`, `telegram`, `mail`), ушло ли
 * (`sent`, `failed`, `skipped`) и открыли ли. Пишет `RecordDelivery`, открытие — `opened` (метка `nt` в ссылке пуша,
 * Telegram и письма — `TrackNoticeOpen`; нажатие в ленте и на карточке — маячок `seen`).
 */
final class Delivery extends Model
{
    use FastAttributes;

    public const CHANNELS = ['feed', 'banner', 'push', 'telegram', 'mail'];

    public const UPDATED_AT = null;

    protected $table = 'notice_deliveries';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['important' => 'boolean', 'opened_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Человек открыл уведомление: первое открытие — у всех строк этого уведомления (открыл — значит, дошло). */
    public static function opened(User $user, string $noticeId, string $via): void
    {
        if (Impersonation::active() || request()->prefetch() || ! Str::isUuid($noticeId) || ! in_array($via, self::CHANNELS, true)) {
            return;
        }
        // Без ожидания диска, как отметки просмотра (`ReadOffer`): переход не ждёт журнала.
        DB::connection('pgsql_async')->table('notice_deliveries')->where('notice_id', $noticeId)->where('user_id', $user->id)
            ->whereNull('opened_at')->update(['opened_at' => now(), 'opened_via' => $via]);
    }

    public static function prune(): void
    {
        self::where('created_at', '<', now()->subDays(180))->delete();
    }
}
