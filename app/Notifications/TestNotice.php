<?php

namespace App\Notifications;

/** Пробное уведомление из настроек: видно, что пуш и почта доходят. */
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
        return '/lk/uvedomleniya/nastroyki';
    }

    public function critical(): bool
    {
        return true;
    }
}
