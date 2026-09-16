<?php

namespace App\Users\Actions;

use App\Users\Invite;
use App\Users\Role;
use App\Users\User;

/**
 * Пригласительная ссылка админа: менеджеру — одноразовая (пришедший сразу
 * менеджер, дальше ссылка мертва), покупателю — от имени выбранного менеджера,
 * как если бы тот сделал её сам. Одна дверь для CRM и кабинета на сайте.
 */
final class IssueAdminInvite
{
    /** @param array{role: string, label?: ?string, manager_id?: mixed} $data */
    public function __invoke(User $admin, array $data, bool $phone, bool $email): Invite
    {
        $manager = $data['role'] === Role::Manager->value;

        return Invite::create([
            'code' => Invite::freshCode(),
            'role' => $manager ? Role::Manager : Role::Buyer,
            'created_by' => $admin->id,
            'manager_id' => $manager ? null : (int) $data['manager_id'],
            'label' => trim((string) ($data['label'] ?? '')) ?: null,
            'max_uses' => $manager ? 1 : null,
            'fields' => $manager ? ['phone' => true, 'email' => true] : ['phone' => $phone, 'email' => $email],
        ]);
    }

    /** Правила формы — общие для обоих контроллеров. */
    public static function rules(): array
    {
        return [
            'role' => ['required', \Illuminate\Validation\Rule::in([Role::Manager->value, Role::Buyer->value])],
            'label' => ['nullable', 'string', 'max:60'],
            'manager_id' => ['required_if:role,buyer', 'nullable', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', Role::Manager->value)],
        ];
    }
}
