<?php

namespace App\Http\Cabinet;

use App\Offers\Actions\HideOffers;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Users\BuyerGroup;
use Illuminate\Http\Request;

/** Группы покупателей: имя, состав, что группа видит. */
class GroupController
{
    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $me = $request->user();
        $group = $me->ownGroups()->create(['name' => trim($data['name']), 'position' => ($me->ownGroups()->max('position') ?? 0) + 1]);

        return redirect("/account/buyers/groups/{$group->id}")->with('toast', 'Группа создана');
    }

    public function show(Request $request, BuyerGroup $group)
    {
        $me = $request->user();
        abort_unless($group->manager_id === $me->id, 404);
        $group->load('members');
        $offerIds = Showing::where('group_id', $group->id)->pluck('offer_id');

        return view('cabinet.buyers.group', [
            'group' => $group,
            // Участники сверху — состав читается сразу, остальных можно добавить ниже.
            'buyers' => $me->buyers()->orderBy('name')->get()->sortByDesc(fn ($b) => $group->members->contains('id', $b->id))->values(),
            'offers' => Offer::with(['brand', 'model', 'settlement', 'media', 'favorites'])->whereIn('id', $offerIds)->where('state', OfferState::Open)->orderByDesc('published_at')->get(),
        ]);
    }

    public function update(Request $request, BuyerGroup $group)
    {
        abort_unless($group->manager_id === $request->user()->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $group->update(['name' => trim($data['name'])]);

        return back()->with('toast', 'Сохранено');
    }

    public function members(Request $request, BuyerGroup $group)
    {
        $me = $request->user();
        abort_unless($group->manager_id === $me->id, 404);
        $ids = collect($request->input('users', []))->map(fn ($v) => (int) $v)->all();
        $allowed = $me->buyers()->whereIn('id', $ids)->pluck('id')->all();
        $group->members()->sync(collect($allowed)->mapWithKeys(fn ($id) => [$id => ['created_at' => now()]])->all());

        return back()->with('toast', 'Состав сохранён');
    }

    public function destroy(Request $request, BuyerGroup $group, HideOffers $hide)
    {
        $me = $request->user();
        abort_unless($group->manager_id === $me->id, 404);
        // Сначала снимаем показы группы действием — покупатели получат «карточка ушла», потом саму группу.
        $hide($me, Showing::where('group_id', $group->id)->pluck('offer_id')->all(), null, $group);
        $group->delete();

        return redirect('/account/buyers')->with('toast', 'Группа удалена');
    }
}
