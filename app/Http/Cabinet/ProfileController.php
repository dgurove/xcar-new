<?php

namespace App\Http\Cabinet;

use App\Media\PhotoIngest;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Favorite;
use App\Offers\Interest;
use App\Offers\InterestState;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Request as ParkRequest;
use App\Park\RequestState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Nav;
use App\Support\Surface;
use App\Users\Role;
use App\Users\Section;
use App\Workflow\Requirement;
use Illuminate\Http\Request;

class ProfileController
{
    /** Сводка кабинета: плитки-счётчики по поверхности и роли. */
    public function show(Request $request)
    {
        $user = $request->user();
        $surface = Surface::current();
        $button = ['/', 'В каталог'];

        if ($surface === Surface::Park) {
            abort_unless($user->canAccess(Section::Park), 404);
            $tiles = [
                [Vehicle::where('state', VehicleState::Stored)->count(), ['на стоянке', 'на стоянке', 'на стоянке'], '/mashiny'],
                [Vehicle::where('state', VehicleState::Expected)->count(), ['ожидается', 'ожидается', 'ожидается'], '/mashiny?preset=expected'],
                [ParkRequest::where('state', RequestState::New)->count(), ['новая заявка', 'новые заявки', 'новых заявок'], '/'],
            ];
            $button = ['/', 'К заявкам'];
        } elseif ($surface === Surface::Crm) {
            $badges = Nav::badges($user);
            $tiles = [
                [$badges['/'] ?? 0, ['подтверждение ждёт ответа', 'подтверждения ждут ответа', 'подтверждений ждут ответа'], '/?preset=bids'],
                [$badges['/rabota/sdelki'] ?? 0, ['сделка требует внимания', 'сделки требуют внимания', 'сделок требуют внимания'], '/rabota/sdelki'],
                [$badges['/rabota/pochta'] ?? 0, ['непрочитанный тред', 'непрочитанных треда', 'непрочитанных тредов'], '/rabota/pochta?preset=unread'],
                [$badges['/rabota/chaty'] ?? 0, ['непрочитанный чат', 'непрочитанных чата', 'непрочитанных чатов'], '/rabota/chaty?preset=unread'],
                [$badges['/predlozheniya/iz-pisem'] ?? 0, ['письмо с предложением ждёт', 'письма с предложением ждут', 'писем с предложением ждут'], '/predlozheniya/iz-pisem'],
            ];
            $button = ['/', 'К предложениям'];
        } elseif ($user->role === Role::Manager) {
            $tiles = [
                [$user->buyers()->count(), ['покупатель', 'покупателя', 'покупателей'], '/lk/pokupateli'],
                [Interest::where('state', InterestState::New)->whereHas('user', fn ($u) => $u->where('manager_id', $user->id))->count(), ['новый интерес', 'новых интереса', 'новых интересов'], '/lk/interes'],
                [Bid::where('user_id', $user->id)->where('state', BidState::Active)->count(), ['подтверждение на рассмотрении', 'подтверждения на рассмотрении', 'подтверждений на рассмотрении'], '/lk/stavki'],
                [Deal::where('buyer_id', $user->id)->where('state', DealState::Active)->count(), ['принята, идёт сделка', 'приняты, идут сделки', 'принято, идут сделки'], '/lk/sdelki'],
                [Requirement::where('user_id', $user->id)->whereNull('done_at')->count(), ['действие за Вами', 'действия за Вами', 'действий за Вами'], '/lk/sdelki'],
                [Favorite::where('user_id', $user->id)->count(), ['в избранном', 'в избранном', 'в избранном'], '/lk/izbrannoe'],
            ];
        } elseif ($user->isStaff()) {
            $tiles = [
                [Favorite::where('user_id', $user->id)->count(), ['в избранном', 'в избранном', 'в избранном'], '/lk/izbrannoe'],
                [$user->unreadCount(), ['непрочитанное уведомление', 'непрочитанных уведомления', 'непрочитанных уведомлений'], '/lk/uvedomleniya'],
            ];
        } else {
            $tiles = [
                ...($user->isBuyer() ? [[Offer::where('state', OfferState::Open)->visibleTo($user)->count(), ['предложение для вас', 'предложения для вас', 'предложений для вас'], '/']] : []),
                [Interest::where('user_id', $user->id)->count(), ['отмечено в интересе', 'отмечено в интересе', 'отмечено в интересе'], '/lk/interesy'],
                [Favorite::where('user_id', $user->id)->count(), ['в избранном', 'в избранном', 'в избранном'], '/lk/izbrannoe'],
            ];
        }

        return view('cabinet.index', ['user' => $user, 'tiles' => $tiles, 'button' => $button, 'manager' => $user->isBuyer() ? $user->manager : null]);
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
        // Почту покупатель меняет, только если менеджер разрешил её в приглашении.
        $user->update(['name' => $data['name']] + ($user->mayHave('email') ? ['email' => $data['email'] ?: null] : []));
        if ($request->boolean('remove_avatar')) {
            $user->clearMediaCollection('avatar');
        }
        if ($request->hasFile('avatar')) {
            // Исходник с телефона до 8 МБ не нужен: аватар живёт в 128 px.
            app(PhotoIngest::class)->fromUpload($user, 'avatar', $request->file('avatar'), max: 512);
        }

        return back()->with('toast', 'Сохранено');
    }
}
