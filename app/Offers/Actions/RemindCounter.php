<?php

namespace App\Offers\Actions;

use App\Chats\Actions\OpenChat;
use App\Chats\Actions\PlatformMessage;
use App\Notifications\CounterOfferedNotice;
use App\Offers\Bid;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use App\Workflow\Actions\RemindAsks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * «Напомнить через…» в ответе на контрпредложение (19.11.2026, владелец: «такая же схема, как при вопросе „Берёте в работу?“»):
 * менеджер откладывает ответ на 1–6 часов (`RemindAsks::CHOICES`), в срок `offers:tick` снова шлёт контрпредложение —
 * уведомлением (лента, пуш, Telegram с кнопками) и сообщением в чат площадки по машине. Ответ, отзыв и новый контр гасят его.
 */
final class RemindCounter
{
    /** Отложить ответ (или снять напоминание — `null`). */
    public static function set(Bid $bid, ?Carbon $at, User $by): void
    {
        $bid->update(['counter_remind_at' => $at]);
        $bid->offer?->log(OfferEventType::Note, $by, ['text' => $at ? 'Контрпредложение: напомнить '.RemindAsks::when($at) : 'Контрпредложение: напоминание снято']);
    }

    /** Созревшие — разом из `offers:tick`. @return int сколько ушло */
    public function __invoke(): int
    {
        $sent = 0;
        foreach (Bid::whereNotNull('counter_remind_at')->where('counter_remind_at', '<=', now())->pluck('id') as $id) {
            $sent += (int) $this->one($id);
        }

        return $sent;
    }

    private function one(int $id): bool
    {
        // Под замком строки: два тика подряд не пришлют дважды.
        $bid = DB::transaction(function () use ($id) {
            $bid = Bid::whereKey($id)->lockForUpdate()->first();
            if (! $bid?->counter_remind_at || $bid->counter_remind_at->isFuture()) {
                return null;
            }
            $bid->update(['counter_remind_at' => null]);

            return $bid;
        });
        if (! $bid?->hasPendingCounter() || ! $bid->user) {
            return false;
        }
        $bid->load('offer', 'user');
        $bid->user->notify(new CounterOfferedNotice($bid));
        if ($bid->offer->chatOpenFor($bid->user)) {
            app(PlatformMessage::class)(app(OpenChat::class)($bid->offer, $bid->user, greet: false),
                'Напоминаем: контрпредложение '.Money::rub($bid->counter_amount).' по '.$bid->offer->titleWithYear().'. Ответьте в предложении');
        }
        $bid->offer->log(OfferEventType::Note, null, ['text' => 'Напомнили менеджеру о контрпредложении: '.$bid->user->shortName()]);

        return true;
    }
}
