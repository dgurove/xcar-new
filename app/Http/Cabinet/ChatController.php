<?php

namespace App\Http\Cabinet;

use App\Chats\Actions\MarkChatRead;
use App\Chats\Chat;
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
        return view('cabinet.chats', ['chats' => $this->list($request->user()), 'current' => null]);
    }

    /** Экран чата: собеседник в шапке, плашка ТС, последние сообщения; на широком экране слева список. */
    public function show(Request $request, Chat $chat, MarkChatRead $read)
    {
        $user = $request->user();
        abort_unless($chat->canPost($user), 404);
        $chat->load(['offer.brand', 'offer.model', 'offer.media', 'user', 'manager']);
        // Первое непрочитанное — до отметки «прочитано»: лента откроется на нём.
        $firstUnread = $chat->myReadSeq($user) < $chat->messages_count ? $chat->myReadSeq($user) + 1 : 0;
        $read($chat, $user);
        Presence::touch($chat, $user);
        $messages = $chat->messages()->with(['author', 'files'])->reorder('seq', 'desc')->limit(Feed::PAGE)->get()->reverse()->values();

        return view('cabinet.chats', [
            'chat' => $chat, 'messages' => $messages, 'user' => $user, 'firstUnread' => $firstUnread,
            'more' => $messages->isNotEmpty() && $messages->first()->seq > 1,
            'chats' => $this->list($user), 'current' => $chat->id,
        ]);
    }

    /**
     * «Написать» со страницы ТС: чат есть — на его экран; нет — экран с плашкой ТС и пустой лентой,
     * первое сообщение заведёт чат (бокс шлёт его на /offers/{n}/chat и получает адрес ленты).
     */
    public function offer(Request $request, Offer $offer)
    {
        $user = $request->user();
        abort_unless($user->canChat() && $offer->chat_enabled && ($offer->state->isPublic() || $offer->state->acceptsInterest()), 404);
        if ($chat = Chat::where('offer_id', $offer->id)->where('user_id', $user->id)->first()) {
            return redirect('/account/chats/'.$chat->id);
        }
        $offer->load(['brand', 'model', 'media']);

        return view('cabinet.chats', [
            'chat' => null, 'offer' => $offer, 'messages' => collect(), 'user' => $user, 'firstUnread' => 0, 'more' => false,
            'chats' => $this->list($user), 'current' => 'new',
        ]);
    }

    /** «Администрация XCar» в списке есть всегда: обращение заведено — на его экран, нет — пустой экран, первое сообщение заведёт. */
    public function support(Request $request)
    {
        $user = $request->user();
        abort_if($user->isStaff(), 404);
        if ($chat = Chat::whereNull('offer_id')->where('user_id', $user->id)->latest('last_message_at')->first()) {
            return redirect('/account/chats/'.$chat->id);
        }

        return view('cabinet.chats', [
            'chat' => null, 'offer' => null, 'messages' => collect(), 'user' => $user, 'firstUnread' => 0, 'more' => false,
            'chats' => $this->list($user), 'current' => 'support',
        ]);
    }

    private function list(User $me)
    {
        return Chat::where(fn ($w) => $w->where('user_id', $me->id)->orWhere('manager_id', $me->id)->when($me->isStaff(), fn ($w) => $w->orWhereNull('manager_id')))
            ->withLast()->orderByDesc('last_message_at')->paginate(30)->withPath('/account/chats');
    }
}
