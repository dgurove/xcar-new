<?php

namespace App\Offers;

use App\Users\User;
use App\Users\UserGroup;
use Illuminate\Support\Facades\DB;

/**
 * Белый список «Кому показывать в сделке»: менеджеры и группы менеджеров, которым
 * предложение в сделке видно в каталоге с меткой «Идёт сделка». Задержек волн тут
 * нет — видно сразу. Пусто — не видит никто (кроме участников сделки по своим дверям).
 */
final class DealAudience
{
    public const USER = 'user';

    public const GROUP = 'group';

    /**
     * Привести списки из формы к строкам таблицы: [{type, id}]. Чужие id и дубли — мимо.
     *
     * @return list<array{type: string, id: int}>
     */
    public static function rows(array $userIds, array $groupIds): array
    {
        $users = User::withRole(\App\Users\Role::Manager)->whereIn('id', array_map('intval', $userIds))->pluck('id')->all();
        $groups = UserGroup::ofKind(UserGroup::MANAGERS)->whereIn('id', array_map('intval', $groupIds))->pluck('id')->all();

        return [
            ...array_map(fn ($id) => ['type' => self::USER, 'id' => (int) $id], $users),
            ...array_map(fn ($id) => ['type' => self::GROUP, 'id' => (int) $id], $groups),
        ];
    }

    /**
     * Перезаписать белый список предложения. Возвращает, что изменилось и кого newly добавили
     * (id менеджеров с раскрытием групп — им уходит уведомление).
     *
     * @return array{changed: bool, added: list<int>}
     */
    public static function sync(Offer $offer, array $userIds, array $groupIds): array
    {
        $before = self::managerIds($offer);
        $rows = self::rows($userIds, $groupIds);

        $users = collect($rows)->where('type', self::USER)->pluck('id')->all();
        $groups = collect($rows)->where('type', self::GROUP)->pluck('id')->all();

        DB::transaction(function () use ($offer, $rows, $users, $groups) {
            OfferDealAudience::where('offer_id', $offer->id)->where(fn ($w) => $w
                ->where(fn ($u) => $u->where('audience_type', self::USER)->whereNotIn('audience_id', $users ?: [0]))
                ->orWhere(fn ($g) => $g->where('audience_type', self::GROUP)->whereNotIn('audience_id', $groups ?: [0])))->delete();
            foreach ($rows as $r) {
                OfferDealAudience::firstOrCreate(['offer_id' => $offer->id, 'audience_type' => $r['type'], 'audience_id' => $r['id']]);
            }
        });
        $offer->unsetRelation('dealAudience');

        $after = self::managerIds($offer);
        sort($before);
        sort($after);

        return ['changed' => $before !== $after, 'added' => array_values(array_diff($after, $before))];
    }

    /** Стереть белый список — сделка кончилась или предложение вернулось в продажу. */
    public static function clear(Offer $offer): void
    {
        OfferDealAudience::where('offer_id', $offer->id)->delete();
        $offer->unsetRelation('dealAudience');
    }

    /** Все менеджеры белого списка: выбранные сами плюс состав выбранных групп. */
    public static function managerIds(Offer $offer): array
    {
        $rows = $offer->relationLoaded('dealAudience') ? $offer->dealAudience : $offer->dealAudience()->get();
        $users = $rows->where('audience_type', self::USER)->pluck('audience_id')->map(fn ($id) => (int) $id)->all();
        $groups = $rows->where('audience_type', self::GROUP)->pluck('audience_id')->all();
        if ($groups) {
            $users = [...$users, ...DB::table('user_group_user')->whereIn('group_id', $groups)->pluck('user_id')->map(fn ($id) => (int) $id)->all()];
        }

        return array_values(array_unique($users));
    }

    /** Видит ли менеджер сделку как предложение: сам в списке или его группа. */
    public static function visibleTo(Offer $offer, ?User $user): bool
    {
        if (! $user) {
            return false;
        }
        $rows = $offer->relationLoaded('dealAudience') ? $offer->dealAudience : $offer->dealAudience()->get();
        if ($rows->contains(fn ($r) => $r->audience_type === self::USER && (int) $r->audience_id === $user->id)) {
            return true;
        }
        $groups = $rows->where('audience_type', self::GROUP)->pluck('audience_id')->all();

        return $groups && DB::table('user_group_user')->where('user_id', $user->id)->whereIn('group_id', $groups)->exists();
    }

    /** Сводка для редактора: «Никому» или кто видит. */
    public static function summary(Offer $offer): string
    {
        $rows = $offer->relationLoaded('dealAudience') ? $offer->dealAudience : $offer->dealAudience()->get();
        if ($rows->isEmpty()) {
            return 'Никому';
        }
        $groups = UserGroup::whereIn('id', $rows->where('audience_type', self::GROUP)->pluck('audience_id'))->pluck('name', 'id');
        $users = User::whereIn('id', $rows->where('audience_type', self::USER)->pluck('audience_id'))->orderBy('name')->get();
        $parts = [
            ...$rows->where('audience_type', self::GROUP)->map(fn ($r) => $groups[$r->audience_id] ?? null)->filter()->all(),
            ...$users->map(fn ($u) => $u->shortName())->all(),
        ];
        $text = implode(', ', $parts);

        return $text === '' ? 'Никому' : mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
