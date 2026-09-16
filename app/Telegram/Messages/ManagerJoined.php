<?php

namespace App\Telegram\Messages;

use App\Support\Surface;
use App\Users\User;

/** По одноразовой ссылке админа пришёл новый менеджер или сотрудник: доступ у него уже есть, решать нечего. */
final class ManagerJoined extends Message
{
    public function __construct(private User $manager) {}

    protected function title(): string
    {
        return 'Новый '.mb_strtolower($this->manager->role->label());
    }

    protected function lines(): array
    {
        $creator = $this->manager->invite?->creator;

        return [
            $this->manager->name,
            $this->manager->phone ? $this->manager->phoneFormatted() : null,
            $this->manager->email,
            $creator ? 'По ссылке: '.$creator->name : null,
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
