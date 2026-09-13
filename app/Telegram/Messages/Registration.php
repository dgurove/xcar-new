<?php

namespace App\Telegram\Messages;

use App\Support\Surface;
use App\Users\Role;
use App\Users\User;

/** Зарегистрировался новый человек: кто он и что с ним делать. */
final class Registration extends Message
{
    public function __construct(private User $user) {}

    protected function title(): string
    {
        return 'Новый пользователь';
    }

    protected function lines(): array
    {
        return [$this->user->name, $this->user->phoneFormatted(), $this->user->email, self::moment($this->user->created_at)];
    }

    protected function decisions(): array
    {
        $buttons = [];
        foreach ([Role::Visitor, Role::Manager, Role::Moderator, Role::Admin] as $role) {
            $buttons[] = ['text' => $role->label(), 'callback_data' => "access:{$this->user->id}:{$role->value}"];
        }
        $buttons[] = ['text' => 'Отклонить', 'callback_data' => "access:{$this->user->id}:reject"];

        return $buttons;
    }

    protected function link(): array
    {
        return ['text' => 'Открыть в CRM', 'url' => Surface::Crm->url('/nastroyki/polzovateli?q='.$this->user->phone)];
    }

    /** След решения остаётся в чате вместо журнала. */
    public function decided(): string
    {
        $user = $this->user->fresh();
        if ($user->isRejected()) {
            return 'Отклонён '.self::moment($user->rejected_at);
        }

        return 'Роль: '.$user->role->label().', доступ открыт '.self::moment($user->approved_at ?? now());
    }
}
