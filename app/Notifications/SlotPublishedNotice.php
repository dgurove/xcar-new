<?php

namespace App\Notifications;

use App\Support\Plural;

/**
 * Слот вышел (или наступила волна показа слота): одно уведомление на пачку вместо «Нового предложения» на каждое
 * (владелец 03.10.2026). Тема — каталог: одна строка в ленте, гаснет, когда менеджер зашёл в «Предложения».
 */
final class SlotPublishedNotice extends Notice
{
    public function __construct(private int $count) {}

    public function title(): string
    {
        return 'Опубликовано '.$this->count.' '.Plural::of($this->count, ['предложение', 'предложения', 'предложений']);
    }

    public function href(): string
    {
        return '/offers';
    }

    public function category(): string
    {
        return 'offers';
    }
}
