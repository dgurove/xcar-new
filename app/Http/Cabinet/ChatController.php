<?php

namespace App\Http\Cabinet;

use App\Chats\Actions\MarkChatRead;
use App\Chats\Chat;
use App\Chats\Direct;
use App\Chats\Presence;
use App\Http\Site\ChatController as Feed;
use App\Offers\Offer;
use App\Users\User;
use Illuminate\Http\Request;

/**
 * Чаты человека: свои по предложениям и обращение с сайта, а у менеджера — ещё чаты его покупателей,
 * где он вторая сторона. Сотруднику — и чаты площадки: те же, что в CRM, отвечать можно отсюда,
 * без ухода на другой хост (установленное приложение открывало бы его во встроенном браузере).
 * Список и экран — как в мессенджере: на широком экране рядом.
 */
class ChatController
{
    public function index(Request $request)
    {
        return view('cabinet.chats', ['chats' => $this->list($request->user(), $request), 'current' => null, 'q' => trim((string) $request->query('q')), 'base' => Chat::ROOT]);
    }

    /** Экран чата: собеседник в шапке, плашка ТС, последние сообщения; на широком экране слева список. */
    public function show(Request $request, Chat $chat, MarkChatRead $read)
    {
        $user = $request->user();
        abort_unless($chat->canPost($user), 404);
        $chat->load(['offer', 'offer.media', 'user', 'manager']);
        // Первое непрочитанное — до отметки «прочитано»: лента откроется на нём.
        $firstUnread = $chat->myReadSeq($user) < $chat->messages_count ? $chat->myReadSeq($user) + 1 : 0;
        $read($chat, $user);
        Presence::touch($chat, $user);
        $messages = $chat->messages()->with(['author', 'files', 'deletedBy'])->reorder('seq', 'desc')->limit(Feed::PAGE)->get()->reverse()->values();

        return view('cabinet.chats', [
            'chat' => $chat, 'messages' => $messages, 'user' => $user, 'firstUnread' => $firstUnread,
            'more' => $messages->isNotEmpty() && $messages->first()->seq > 1,
            'chats' => $this->list($user, $request), 'current' => $chat->id, 'base' => Chat::ROOT,
        ]);
    }

    /**
     * «Написать» со страницы ТС: чат есть — на его экран; нет — экран с плашкой ТС и пустой лентой,
     * первое сообщение заведёт чат (бокс шлёт его на /offers/{n}/chat и получает адрес ленты).
     */
    public function offer(Request $request, Offer $offer)
    {
        $user = $request->user();
        // Свой чат — на его экран и тогда, когда предложение от человека ушло; новый — только видящему (`chatOpenFor`).
        if ($chat = Chat::where('offer_id', $offer->id)->where('user_id', $user->id)->first()) {
            return redirect(Chat::path($chat->id));
        }
        abort_unless($offer->chatOpenFor($user), 404);
        $offer->load(['media']);

        return view('cabinet.chats', [
            'chat' => null, 'offer' => $offer, 'messages' => collect(), 'user' => $user, 'firstUnread' => 0, 'more' => false,
            'chats' => $this->list($user, $request), 'current' => 'new', 'base' => Chat::ROOT,
        ]);
    }

    /**
     * «Администрация XCAR» в списке есть всегда (покупателю менеджера — его менеджер, 12.10.2026): обращение заведено — на
     * его экран, нет — пустой экран, первое сообщение заведёт.
     */
    public function support(Request $request)
    {
        $user = $request->user();
        abort_if($user->isAdmin(), 404);
        $manager = $user->isBuyer() ? $user->manager_id : null;
        if ($chat = Chat::whereNull('offer_id')->where('user_id', $user->id)->where('manager_id', $manager)->latest('last_message_at')->first()) {
            return redirect(Chat::path($chat->id));
        }

        return view('cabinet.chats', [
            'chat' => null, 'offer' => null, 'messages' => collect(), 'user' => $user, 'firstUnread' => 0, 'more' => false,
            'chats' => $this->list($user, $request), 'current' => 'support', 'base' => Chat::ROOT,
        ]);
    }

    /**
     * Личка с человеком (22.11.2026, `Chats\Direct`): есть — на её экран, нет — пустой экран, первое сообщение её заведёт.
     * Выбор из шторки «Написать» приходит `?user=` (форма GET): «Администрация XCAR» — `support`.
     */
    public function with(Request $request, ?User $user = null)
    {
        $me = $request->user();
        $to = Direct::target($request, $user);
        if ($to === 'support') {
            return redirect(Chat::path('support'));
        }
        if ($chat = Direct::between($me, $to)) {
            return redirect(Chat::path($chat->id));
        }
        abort_unless(Direct::canStart($me, $to), 404);
        if (! $user) {
            return redirect(Chat::directPath($to->id));
        }

        return view('cabinet.chats', [
            'chat' => null, 'offer' => null, 'with' => $to, 'messages' => collect(), 'user' => $me, 'firstUnread' => 0, 'more' => false,
            'chats' => $this->list($me, $request), 'current' => 'with', 'base' => Chat::ROOT,
        ]);
    }

    /** Мои чаты; лупа над списком (`q`) — имя собеседника, марка или модель ТС, номер предложения. */
    private function list(User $me, ?Request $request = null)
    {
        $q = trim((string) $request?->query('q'));
        // Люди под запрос — первыми над чатами (`Direct::people`, 22.11.2026), только на первой странице; их лички ниже не повторяются.
        $people = $q !== '' && (int) $request?->query('page', 1) <= 1 ? Direct::people($me, $q) : collect();
        view()->share('people', $people);

        return Chat::where('messages_count', '>', 0)
            ->when($people->isNotEmpty(), fn ($w) => $w->whereNotIn('chats.id', $people->pluck('chat.id')->filter()))->where(fn ($w) => $w->where('user_id', $me->id)->orWhere('manager_id', $me->id)->when($me->isAdmin(), fn ($w) => $w->orWhereNull('manager_id')))
            ->search($q)
            ->withLast()->orderByDesc('last_message_at')->paginate(30)->withPath(Chat::ROOT)->withQueryString();
    }
}
