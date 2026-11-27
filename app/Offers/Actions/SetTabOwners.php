<?php

namespace App\Offers\Actions;

use App\Http\Admin\OfferController;
use App\Offers\Responsibility;
use App\Offers\TabOwner;
use Illuminate\Support\Facades\DB;

/**
 * Ответственные за вкладку «Предложений» (18.11.2026, «Настройки → Ответственные»): ставит ровно этих сотрудников. Ключ — вкладка
 * колеса (с 24.11.2026 и вкладка ожидания: «Менеджер должен нам» — Андрею), люди — только те, кому она открыта (`OfferController::presets`); лишнее отбрасывается.
 * С 26.11.2026 у человека — за всю вкладку или только за горящее (`scope`) и шлём ли пуш и Telegram (`notify`); строка
 * `Responsibility::BINDING` — горящий срок обязывающего во всех вкладках, без уведомлений.
 */
final class SetTabOwners
{
    /**
     * @param  list<int>  $userIds
     * @param  array<int|string, string>  $scopes  человек → `all` | `urgent`
     * @param  array<int|string, mixed>  $notify  человек → уведомлять (есть ключ — да)
     */
    public function __invoke(string $preset, array $userIds, array $scopes = [], array $notify = []): void
    {
        $binding = $preset === Responsibility::BINDING;
        // «Уведомлять обо всём» (27.11.2026): не вкладка, любой сотрудник, без «за что» — только пуш и Telegram обо всём.
        $everything = $preset === Responsibility::EVERYTHING;
        abort_unless($binding || $everything || isset(OfferController::PRESETS[$preset]), 404);
        $allowed = TabOwner::staff()->filter(fn ($u) => $binding || $everything || isset(OfferController::presets($u)[$preset]))->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', $userIds), $allowed));
        // Форма без выбора «за что» (страница, открытая до 26.11.2026) — прежние строки не трогаем: иначе уведомления молча гасли бы.
        $detailed = $scopes !== [];
        DB::transaction(function () use ($preset, $ids, $scopes, $notify, $binding, $everything, $detailed) {
            TabOwner::where('preset', $preset)->whereNotIn('user_id', $ids)->delete();
            foreach ($ids as $id) {
                $row = TabOwner::firstOrNew(['preset' => $preset, 'user_id' => $id]);
                if ($detailed || $everything || ! $row->exists) {
                    $row->scope = ! $binding && ($scopes[$id] ?? null) === Responsibility::URGENT ? Responsibility::URGENT : Responsibility::ALL;
                    $row->notify = $everything || (! $binding && ($detailed ? ! empty($notify[$id]) : true));
                }
                $row->save();
            }
        });
        TabOwner::forget();
    }
}
