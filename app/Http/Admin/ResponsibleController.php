<?php

namespace App\Http\Admin;

use App\Offers\Actions\SetTabOwners;
use App\Offers\Responsibility;
use App\Offers\TabOwner;
use Illuminate\Http\Request;

/**
 * «Настройки → Ответственные» (18.11.2026, владелец выбрал вариант 1 макета): все вкладки «Предложений» строками по разделам
 * колеса, у вкладки — аватары ответственных; нажатие — шторка с сотрудниками галками. По ним «Только мои» в колесе.
 * С 26.11.2026 у отмеченного — «Вся вкладка | Только горящее» и «Пуш и Telegram», первой строкой — горящий срок обязывающего во
 * всех вкладках (`Responsibility`).
 */
final class ResponsibleController
{
    public function index()
    {
        $staff = TabOwner::staff();
        $open = $staff->mapWithKeys(fn ($u) => [$u->id => OfferController::presets($u)]);
        $all = TabOwner::all();
        $owners = $all->groupBy('preset')->map(fn ($rows) => $staff->whereIn('id', $rows->pluck('user_id'))->values());
        // За что и с уведомлениями ли (26.11.2026): вкладка → человек → строка.
        $rows = $all->groupBy('preset')->map(fn ($rows) => $rows->keyBy('user_id'));

        // Группы — разделы колеса (`SECTIONS`): «Все» — своей группой без заголовка.
        $groups = [];
        $head = null;
        foreach (OfferController::PRESETS as $key => $label) {
            $head = OfferController::SECTIONS[$key] ?? $head;
            // «Предложения из закупок» — без ответственного (24.11.2026, владелец).
            if ($key === OfferController::CARCADE_TAB) {
                continue;
            }
            $groups[$head ?? ''][$key] = $label;
        }

        return view('admin.responsibles.index', [
            'groups' => $groups,
            'owners' => $owners,
            'rows' => $rows,
            'staff' => fn (string $preset) => $preset === Responsibility::BINDING ? $staff : $staff->filter(fn ($u) => isset($open[$u->id][$preset]))->values(),
        ]);
    }

    public function update(Request $request, string $preset, SetTabOwners $set)
    {
        $set($preset, (array) $request->input('users', []), (array) $request->input('scope', []), (array) $request->input('notify', []));

        return back()->with('toast', 'Ответственные: '.(OfferController::PRESETS[$preset] ?? 'горящий срок обязывающего'));
    }
}
