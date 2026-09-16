<?php

namespace App\Notifications;

final class AccessOpenedNotice extends Notice
{
    public function title(): string
    {
        return 'Доступ открыт';
    }

    public function text(): ?string
    {
        return 'Предложения и галерея ждут вас.';
    }

    public function href(): string
    {
        return '/';
    }

    public function category(): string
    {
        return 'people';
    }

    public function critical(): bool
    {
        return true;
    }
}
