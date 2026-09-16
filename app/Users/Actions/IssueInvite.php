<?php

namespace App\Users\Actions;

use App\Users\Invite;
use App\Users\Role;
use App\Users\User;
use Illuminate\Validation\Rule;

/**
 * Одна дверь для пригласительных ссылок — из кабинета на сайте и из CRM.
 * Менеджер зовёт покупателей: многоразовая, сразу в группу, что покупатель укажет.
 * Админ зовёт менеджера — одноразовая, пришедший сразу менеджер — или покупателя
 * от имени выбранного менеджера, как если бы тот сделал ссылку сам.
 */
final class IssueInvite
{
    public function __invoke(User $by, array $data): Invite
    {
        $fields = ['phone' => (bool) ($data['phone'] ?? false), 'email' => (bool) ($data['email'] ?? false)];
        $label = trim((string) ($data['label'] ?? '')) ?: null;

        if (! $by->isAdmin()) {
            return Invite::create([
                'code' => Invite::freshCode(),
                'role' => Role::Buyer,
                'manager_id' => $by->id,
                'created_by' => $by->id,
                'label' => $label,
                'group_id' => $data['group_id'] ?? null ?: null,
                'fields' => $fields,
            ]);
        }

        $manager = ($data['role'] ?? null) === Role::Manager->value;

        return Invite::create([
            'code' => Invite::freshCode(),
            'role' => $manager ? Role::Manager : Role::Buyer,
            'manager_id' => $manager ? null : (int) $data['manager_id'],
            'created_by' => $by->id,
            'label' => $label,
            'max_uses' => $manager ? 1 : null,
            'fields' => $manager ? ['phone' => true, 'email' => true] : $fields,
        ]);
    }

    /** Правила формы — по тому, кто создаёт. */
    public static function rules(User $by): array
    {
        $rules = ['label' => ['nullable', 'string', 'max:60']];
        if ($by->isAdmin()) {
            $rules['role'] = ['required', Rule::in([Role::Manager->value, Role::Buyer->value])];
            $rules['manager_id'] = ['required_if:role,buyer', 'nullable', Rule::exists('users', 'id')->where('role', Role::Manager->value)];
        } else {
            $rules['group_id'] = ['nullable', Rule::exists('buyer_groups', 'id')->where('manager_id', $by->id)];
        }

        return $rules;
    }
}
