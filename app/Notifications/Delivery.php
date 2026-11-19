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

    /**
     * Строка журнала одной дверью (19.11.2026: её собирали руками в пяти местах). `$about` — уведомление или его
     * описание из задания (`kind`, `label`, `category`, `important`); ошибка записи не мешает доставке.
     *
     * @param  Notice|array{kind: string, label: string, category: string, important: bool}  $about
     */
    public static function log(Notice|array $about, string $noticeId, int $userId, string $channel, string $status, ?string $detail = null, ?int $devices = null): void
    {
        $meta = $about instanceof Notice
            ? ['kind' => $about->kind(), 'label' => $about->label(), 'category' => $about->category(), 'important' => $about->important()]
            : $about;
        try {
            self::create([
                'notice_id' => $noticeId, 'user_id' => $userId, 'kind' => $meta['kind'], 'label' => mb_substr($meta['label'], 0, 80),
                'category' => $meta['category'], 'important' => $meta['important'], 'channel' => $channel, 'status' => $status,
                'detail' => $detail === null ? null : mb_substr($detail, 0, 160), 'devices' => $devices, 'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Журнал — не повод не доставить.
        }
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
