<?php

namespace App\Notifications;

use App\Push\WebPushChannel;
use App\Users\User;

/**
 * Пробное уведомление из настроек: видно, что почта и Telegram доходят. Пуш — не отсюда: обычный пуш уходит только
 * подпискам «своего» приложения, а проверить нужно каждое устройство — `WebPushChannel::test` по всем (20.10.2026).
 */
final class TestNotice extends Notice
{
    public function via(User $user): array
    {
        return array_values(array_diff(parent::via($user), [WebPushChannel::class]));
    }

    public function title(): string
    {
        return 'Так выглядят уведомления xcar';
    }

    public function text(): ?string
    {
        return 'Всё работает';
    }

    public function href(): string
    {
        return '/account/notifications/settings';
    }

    public function critical(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => 'Так выглядят уведомления xcar', 'lines' => ['Всё работает'], 'button' => 'Открыть'];
    }
}
