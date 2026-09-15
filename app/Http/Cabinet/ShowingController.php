<?php

namespace App\Http\Cabinet;

use App\Offers\Actions\HideOffers;
use App\Offers\Actions\ShowOffers;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Offers\Showing;
use App\Support\Plural;
use App\Users\BuyerGroup;
use App\Users\User;
use Illuminate\Http\Request;

/** Показы: шторка «Показать…» у предложения или пачки, выбор предложений для покупателя или группы. */
class ShowingController
{
    /** Тело шторки: группы и покупатели чипами; для одного предложения отмечено текущее. */
    public function create(Request $request)
    {
        $me = $request->user();
        $ids = collect($request->input('offers', []))->map(fn ($v) => (int) $v)->filter()->unique()->take(100)->all();
        $offers = Offer::with(['brand', 'model'])->whereIn('id', $ids)->where('state', OfferState::Open)->visibleTo($me)->get();
        abort_if($offers->isEmpty(), 404);
        $single = $offers->count() === 1 ? $offers->first() : null;
        $current = $single ? Showing::where('offer_id', $single->id)->where('manager_id', $me->id)->get() : collect();

        return view('cabinet.showings.form', [
            'offers' => $offers,
            'single' => $single,
            'groups' => $me->ownGroups()->withCount('members')->get(),
            'buyers' => $me->buyers()->orderBy('name')->get(),
            'checkedUsers' => $current->pluck('user_id')->filter()->all(),
            'checkedGroups' => $current->pluck('group_id')->filter()->all(),
            'back' => $request->query('back', url()->previous()),
        ]);
    }

    /** Выбор предложений для покупателя или группы: мини-карточки с галками. */
    public function pick(Request $request)
    {
        $me = $request->user();
        $buyer = $request->integer('user') ? $me->buyers()->findOrFail($request->integer('user')) : null;
        $group = $request->integer('group') ? $me->ownGroups()->findOrFail($request->integer('group')) : null;
        abort_unless($buyer || $group, 404);
        $q = Offer::with(['brand', 'model', 'media'])->where('state', OfferState::Open)->visibleTo($me)->orderByDesc('published_at');
        if ($term = trim((string) $request->query('q'))) {
            $q->search($term);
        }
        $offers = $q->limit(60)->get();
        $already = $buyer
            ? Offer::whereIn('id', $offers->pluck('id'))->visibleTo($buyer)->pluck('id')->all()
            : Showing::where('group_id', $group->id)->pluck('offer_id')->all();

        return view('cabinet.showings.pick', ['offers' => $offers, 'already' => $already, 'buyer' => $buyer, 'group' => $group, 'q' => $term]);
    }

    public function store(Request $request, ShowOffers $show)
    {
        $me = $request->user();
        $data = $request->validate([
            'offers' => ['required', 'array', 'max:100'],
            'offers.*' => ['integer'],
            'users' => ['nullable', 'array'],
            'users.*' => ['integer'],
            'groups' => ['nullable', 'array'],
            'groups.*' => ['integer'],
            'sync' => ['nullable', 'boolean'],
            'back' => ['nullable', 'string', 'max:300'],
        ]);
        $users = array_map('intval', $data['users'] ?? []);
        $groups = array_map('intval', $data['groups'] ?? []);
        $result = $show($me, array_map('intval', $data['offers']), $users, $groups, $request->boolean('sync'));

        $toast = $request->boolean('sync')
            ? 'Сохранено'
            : ($result['buyers']
                ? 'Открыто '.$result['buyers'].' '.Plural::of($result['buyers'], ['покупателю', 'покупателям', 'покупателям'])
                : 'Уже было открыто');
        $back = $data['back'] ?? null;

        return ($back && str_starts_with($back, '/') ? redirect($back) : back())->with('toast', $toast);
    }

    public function destroy(Request $request, HideOffers $hide)
    {
        $me = $request->user();
        $data = $request->validate(['offer' => ['required', 'integer'], 'user' => ['nullable', 'integer'], 'group' => ['nullable', 'integer']]);
        $buyer = ! empty($data['user']) ? User::find($data['user']) : null;
        $group = ! empty($data['group']) ? BuyerGroup::find($data['group']) : null;
        $hide($me, [(int) $data['offer']], $buyer, $group);

        return back()->with('toast', 'Закрыто');
    }
}
