<?php

namespace App\Notifications;

/** Пробное уведомление из настроек: видно, что пуш, почта и Telegram доходят. */
final class TestNotice extends Notice
{
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

    public function telegram(): bool
    {
        return true;
    }
}
