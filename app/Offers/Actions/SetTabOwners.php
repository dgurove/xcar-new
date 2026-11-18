<?php

namespace App\Offers\Actions;

use App\Http\Admin\OfferController;
use App\Offers\TabOwner;
use Illuminate\Support\Facades\DB;

/**
 * Ответственные за вкладку «Предложений» (18.11.2026, «Настройки → Ответственные»): ставит ровно этих сотрудников. Ключ — только
 * вкладка колеса, не вкладка ожидания (`TabOwner::assignable`), люди — только те, кому она открыта (`OfferController::presets`); лишнее отбрасывается.
 */
final class SetTabOwners
{
    /** @param list<int> $userIds */
    public function __invoke(string $preset, array $userIds): void
    {
        abort_unless(isset(OfferController::PRESETS[$preset]) && TabOwner::assignable($preset), 404);
        $allowed = TabOwner::staff()->filter(fn ($u) => isset(OfferController::presets($u)[$preset]))->pluck('id')->all();
        $ids = array_values(array_intersect(array_map('intval', $userIds), $allowed));
        DB::transaction(function () use ($preset, $ids) {
            TabOwner::where('preset', $preset)->whereNotIn('user_id', $ids)->delete();
            foreach ($ids as $id) {
                TabOwner::firstOrCreate(['preset' => $preset, 'user_id' => $id]);
            }
        });
        TabOwner::forget();
    }
}
