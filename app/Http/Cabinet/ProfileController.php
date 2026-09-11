<?php

namespace App\Http\Cabinet;

use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Favorite;
use App\Offers\Interest;
use App\Support\Nav;
use App\Users\Role;
use App\Workflow\Requirement;
use Illuminate\Http\Request;

class ProfileController
{
    /** Сводка кабинета: плитки-счётчики по роли. */
    public function show(Request $request)
    {
        $user = $request->user();
        $tiles = [];
        if ($user->isStaff()) {
            $badges = Nav::badges($user);
            $tiles = [
                [$badges['/admin/offers'] ?? 0, ['ставка ждёт ответа', 'ставки ждут ответа', 'ставок ждут ответа'], '/admin/offers?preset=bids'],
                [$badges['/admin/sdelki'] ?? 0, ['сделка требует внимания', 'сделки требуют внимания', 'сделок требуют внимания'], '/admin/sdelki'],
                [$badges['/admin/pochta'] ?? 0, ['непрочитанный тред', 'непрочитанных треда', 'непрочитанных тредов'], '/admin/pochta'],
                [\App\Mail\Candidate::where('state', \App\Mail\CandidateState::New)->count(), ['кандидат из писем', 'кандидата из писем', 'кандидатов из писем'], '/admin/kandidaty'],
            ];
        } elseif ($user->role === Role::Manager) {
            $tiles = [
                [Bid::where('user_id', $user->id)->where('state', BidState::Active)->count(), ['заявка на рассмотрении', 'заявки на рассмотрении', 'заявок на рассмотрении'], '/lk/stavki'],
                [Deal::where('buyer_id', $user->id)->where('state', DealState::Active)->count(), ['принята, идёт сделка', 'приняты, идут сделки', 'принято, идут сделки'], '/lk/sdelki'],
                [Requirement::where('user_id', $user->id)->whereNull('done_at')->count(), ['действие за Вами', 'действия за Вами', 'действий за Вами'], '/lk/sdelki'],
                [Favorite::where('user_id', $user->id)->count(), ['в избранном', 'в избранном', 'в избранном'], '/lk/izbrannoe'],
            ];
        } else {
            $tiles = [
                [Interest::where('user_id', $user->id)->count(), ['отмечено в интересе', 'отмечено в интересе', 'отмечено в интересе'], '/lk/interesy'],
                [Favorite::where('user_id', $user->id)->count(), ['в избранном', 'в избранном', 'в избранном'], '/lk/izbrannoe'],
            ];
        }

        return view('cabinet.index', ['user' => $user, 'tiles' => $tiles]);
    }

    public function profile(Request $request)
    {
        return view('cabinet.profile', [
            'user' => $request->user(),
            'passkeys' => $request->user()->webAuthnCredentials()->latest()->get(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:190', 'unique:users,email,'.$request->user()->id],
            'avatar' => ['nullable', 'image', 'max:8192'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $user->update(['name' => $data['name'], 'email' => $data['email'] ?: null]);
        if ($request->boolean('remove_avatar')) {
            $user->clearMediaCollection('avatar');
        }
        if ($request->hasFile('avatar')) {
            $user->addMediaFromRequest('avatar')->toMediaCollection('avatar');
        }

        return back()->with('toast', 'Сохранено');
    }
}
