<?php

namespace App\Telegram\Messages;

use App\Support\Surface;
use App\Users\User;

/** По ссылке админа пришёл новый менеджер: доступ у него уже есть, решать нечего. */
final class ManagerJoined extends Message
{
    public function __construct(private User $manager) {}

    protected function title(): string
    {
        return 'Новый менеджер';
    }

    protected function lines(): array
    {
        return [
            $this->manager->name,
            $this->manager->phone ? $this->manager->phoneFormatted() : null,
            $this->manager->email,
            self::moment($this->manager->created_at),
        ];
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'Менеджеры в CRM', 'url' => Surface::Crm->url('/settings/users?preset=managers')];
    }
}
