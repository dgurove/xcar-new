<?php

namespace App\Users\Actions;

use App\Users\CrmArea;
use App\Users\Invite;
use App\Users\Role;
use App\Users\User;
use App\Users\UserGroup;
use Carbon\CarbonInterface;
use Illuminate\Validation\Rule;

/**
 * Одна дверь для пригласительных ссылок — из кабинета на сайте и из CRM.
 * Менеджер зовёт покупателей: многоразовая, сразу в группу, что покупатель укажет.
 * Админ зовёт менеджера, сотрудника или супер-покупателя — одноразовая и со сроком, без названия: пришедший
 * сразу в роли — или покупателя от имени выбранного менеджера, как если бы тот сделал
 * ссылку сам. Другого способа завести человека нет: руками в CRM никто не создаётся.
 */
final class IssueInvite
{
    /** Кем может стать пришедший по ссылке админа CRM. Управляющего и помощника парковки зовёт парковка (`park()`). */
    public const ROLES = [Role::Manager, Role::Moderator, Role::Admin, Role::Buyer, Role::SuperBuyer];

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
            // Модератору — что открыто сверх черновиков.
            ...($role === Role::Moderator ? ['access' => array_values($data['crm_areas'] ?? [])] : []),
            // Менеджер и модератор — сразу в группе своего вида (UserGroup), если выбрана.
            'user_group_id' => match ($role) {
                Role::Manager => ($data['manager_group_id'] ?? null) ?: null,
                Role::Moderator => ($data['moderator_group_id'] ?? null) ?: null,
                default => null,
            },
        ]);
    }

    /**
     * Ссылка управляющему или помощнику парковки — от администратора парковки (из «Пользователей» парковки): одноразовая,
     * со сроком, без названия; «только приёмка» — помощник; город — что он видит (`Park\City`, пусто — весь парк).
     */
    public function park(User $by, CarbonInterface $expiresAt, bool $helper, ?int $city = null): Invite
    {
        return Invite::create([
            'code' => Invite::freshCode(), 'role' => Role::Parking, 'created_by' => $by->id, 'max_uses' => 1,
            'expires_at' => $expiresAt, 'fields' => ['phone' => true, 'email' => true], 'park_readonly' => $helper, 'park_city_id' => $city,
        ]);
    }

    /** Правила формы — по тому, кто создаёт. */
    public static function rules(User $by): array
    {
        $rules = ['label' => ['nullable', 'string', 'max:60']];
        if ($by->isAdmin()) {
            $rules['role'] = ['required', Rule::in(array_map(fn ($r) => $r->value, self::ROLES))];
            $rules['manager_id'] = ['required_if:role,buyer', 'nullable', Rule::exists('users', 'id')->where(fn ($q) => $q->whereJsonContains('roles', Role::Manager->value))];
            $rules['expires_at'] = ['exclude_if:role,buyer', 'required', 'date', 'after:now'];
            $rules['crm_areas'] = ['exclude_unless:role,moderator', 'nullable', 'array'];
            $rules['crm_areas.*'] = [Rule::in(CrmArea::values())];
            $rules['manager_group_id'] = ['exclude_unless:role,manager', 'nullable', Rule::exists('user_groups', 'id')->where('kind', UserGroup::MANAGERS)];
            $rules['moderator_group_id'] = ['exclude_unless:role,moderator', 'nullable', Rule::exists('user_groups', 'id')->where('kind', UserGroup::MODERATORS)];
        } else {
            $rules['group_id'] = ['nullable', Rule::exists('buyer_groups', 'id')->where('manager_id', $by->id)];
        }

        return $rules;
    }
}
