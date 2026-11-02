<?php

namespace App\Telegram\Offers;

use App\Support\FastAttributes;
use App\Users\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Подписчик бота предложений: чей чат и с какого момента ему слать карточки (`cards_since` — подписался или вернулся,
 * открытое раньше не приходит). Пишется при подписке и отметке «остановил» — через `pgsql_async`, как все записи бота:
 * на HDD прода обычная фиксация стоит 160–290 мс. Таблица — только этим соединением, иначе процесс заблокирует сам себя.
 */
final class Subscriber extends Model
{
    use FastAttributes;

    protected $connection = 'pgsql_async';

    protected $table = 'offer_bot_chats';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['blocked_at' => 'datetime', 'cards_since' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Подписан и не остановил бота: бот сам пишет ему о новых — основному боту повторять не нужно (`Notifications\OffersPack`);
     * на сайте — строка в «Предложениях» и шторка подписки.
     */
    public static function subscribed(User $user): bool
    {
        return DB::connection('pgsql_async')->table('offer_bot_chats')->where('user_id', $user->id)->whereNull('blocked_at')->exists();
    }

    /** Кому бот служит: допущенные менеджеры и админы, без демо и закрытых. */
    public static function eligible(?User $user): bool
    {
        return $user !== null && ($user->isManager() || $user->isAdmin()) && $user->isApproved() && ! $user->isRejected() && ! $user->is_demo;
    }
}
