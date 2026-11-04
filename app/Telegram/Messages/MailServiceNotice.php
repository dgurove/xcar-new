<?php

namespace App\Telegram\Messages;

use App\Support\Surface;

/**
 * Служебное письмо ящика предложений, которое людям в почте не нужно, а владельцу — да (04.11.2026): наше письмо не
 * дошло (уведомление системы отбил почтовый сервер), почта просит подтвердить пересылку из ящика сотрудника. Ветка
 * сама уходит в архив (`Mail\Actions\SettleServiceLetter`). Только главному администратору — это технический сбой.
 */
final class MailServiceNotice extends Message
{
    /** @param list<?string> $lines */
    public function __construct(private string $title, private array $lines, private int $threadId) {}

    public function ownerOnly(): bool
    {
        return true;
    }

    protected function title(): string
    {
        return $this->title;
    }

    protected function lines(): array
    {
        return $this->lines;
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'Письмо', 'url' => Surface::Crm->url('/work/mail/'.$this->threadId)];
    }
}
