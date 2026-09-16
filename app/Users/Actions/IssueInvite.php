<?php

namespace App\Users\Actions;

use App\Users\Invite;
use App\Users\Role;
use App\Users\User;
use Illuminate\Validation\Rule;

/**
 * Одна дверь для пригласительных ссылок — из кабинета на сайте и из CRM.
 * Менеджер зовёт покупателей: многоразовая, сразу в группу, что покупатель укажет.
 * Админ зовёт менеджера или сотрудника — одноразовая и со сроком, без названия: пришедший
 * сразу в роли — или покупателя от имени выбранного менеджера, как если бы тот сделал
 * ссылку сам. Другого способа завести человека нет: руками в CRM никто не создаётся.
 */
final class IssueInvite
{
    /** Кем может стать пришедший по ссылке админа. */
    public const ROLES = [Role::Manager, Role::Moderator, Role::Admin, Role::Buyer];

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

        $role = Role::from($data['role']);
        $once = $role !== Role::Buyer;

        return Invite::create([
            'code' => Invite::freshCode(),
            'role' => $role,
            'manager_id' => $once ? null : (int) $data['manager_id'],
            'created_by' => $by->id,
            'label' => $once ? null : $label,
            'max_uses' => $once ? 1 : null,
            'expires_at' => $once ? ($data['expires_at'] ?? null) : null,
            'fields' => $once ? ['phone' => true, 'email' => true] : $fields,
        ]);
    }

    /** Правила формы — по тому, кто создаёт. */
    public static function rules(User $by): array
    {
        $rules = ['label' => ['nullable', 'string', 'max:60']];
        if ($by->isAdmin()) {
            $rules['role'] = ['required', Rule::in(array_map(fn ($r) => $r->value, self::ROLES))];
            $rules['manager_id'] = ['required_if:role,buyer', 'nullable', Rule::exists('users', 'id')->where('role', Role::Manager->value)];
            $rules['expires_at'] = ['exclude_if:role,buyer', 'required', 'date', 'after:now'];
        } else {
            $rules['group_id'] = ['nullable', Rule::exists('buyer_groups', 'id')->where('manager_id', $by->id)];
        }

        return $rules;
    }
}
