<?php

namespace App\Http\Admin;

use App\Chats\Actions\DeleteChat;
use App\Chats\Actions\MarkChatRead;
use App\Chats\Actions\OpenChat;
use App\Chats\Chat;
use App\Chats\Presence;
use App\Http\Site\ChatController as Feed;
use App\Offers\Offer;
use App\Support\ListView;
use App\Users\User;
use Illuminate\Http\Request;

/**
 * Чаты площадки — отвечают сотрудники. Список один, без пилюль и чипов (05.10.2026, владелец: «фильтры только мешают»):
 * непрочитанные сверху, дальше свежие; поиск — лупой над списком. Переписки покупателей с менеджерами здесь не
 * показываются — они в карточке пользователя (там же читаются, `show` их открывает только для чтения).
 * Список и открытый чат — один экран: на широком рядом, на телефоне по очереди.
 */
class ChatController
{
    public function index(Request $request)
    {
        return view('admin.chats.index', $this->list($request) + ['current' => null]);
    }

    /** Чат площадки — с полем ответа; чат покупателя с менеджером — только лента, счётчики не трогаются. */
    public function show(Request $request, Chat $chat, MarkChatRead $read)
    {
        $user = $request->user();
        $chat->load(['offer.brand', 'offer.model', 'offer.media', 'user', 'manager']);
        $firstUnread = ! $chat->isBuyerChat() && $chat->read_seq_staff < $chat->messages_count ? $chat->read_seq_staff + 1 : 0;
        $read($chat, $user);
        Presence::touch($chat, $user);
        $messages = $chat->messages()->with(['author', 'files'])->reorder('seq', 'desc')->limit(Feed::PAGE)->get()->reverse()->values();

        return view('admin.chats.index', $this->list($request) + [
            'chat' => $chat, 'messages' => $messages, 'user' => $user, 'firstUnread' => $firstUnread,
            'more' => $messages->isNotEmpty() && $messages->first()->seq > 1, 'current' => $chat->id,
        ]);
    }

    /**
     * «Написать менеджеру» из карточки гаража, сделки или вывоза (05.10.2026): площадка пишет первой тому, кто с машиной
     * работает. Чат по машине у пары один — есть, откроется он же.
     */
    public function start(Request $request, Offer $offer, User $user, OpenChat $open)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($user->isManager() && ($offer->worksWith($user) || Chat::where('offer_id', $offer->id)->where('user_id', $user->id)->exists()), 404);

        return redirect('/work/chats/'.$open($offer, $user, greet: false)->id);
    }

    /** Спам и реклама в чате площадки — удалить целиком (только админ). Обратно — на список с тем же поиском. */
    public function destroy(Request $request, Chat $chat, DeleteChat $delete)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $delete($chat);
        $back = parse_url(url()->previous(), PHP_URL_QUERY);

        return redirect('/work/chats'.($back ? '?'.$back : ''))->with('toast', 'Чат удалён');
    }

    /** Список чатов площадки и поиск из адреса; открытый чат его не меняет. Старый `?preset=` ничего не значит. */
    private function list(Request $request): array
    {
        $q = trim((string) $request->query('q'));
        $like = '%'.mb_strtolower($q).'%';
        $digits = preg_replace('/\D/', '', $q);
        // Заведённый кнопкой «Написать» и брошенный без сообщения — не строка списка.
        $chats = Chat::withLast()->whereNull('manager_id')->where('messages_count', '>', 0)
            ->when($q !== '', fn ($c) => $c->where(fn ($w) => $w->whereHas('user', fn ($u) => $u->whereRaw('lower(name) like ?', [$like])->when($digits !== '', fn ($u) => $u->orWhere('phone', 'like', '%'.$digits.'%')))
                ->orWhereRaw('lower(guest_name) like ?', [$like])
                ->orWhereHas('offer', fn ($o) => $o->where('number', (int) $q)->orWhereHas('model', fn ($m) => $m->whereRaw('lower(name) like ?', [$like]))->orWhereHas('brand', fn ($b) => $b->whereRaw('lower(name) like ?', [$like])))));
        // Непрочитанные сверху, дальше свежие. Страницы списка ведут на список, даже когда справа открыт чат.
        $chats = $chats->orderByRaw('unread_for_staff > 0 desc')->orderByDesc('last_message_at')
            ->paginate(ListView::perPage($request, ListView::PER_ROWS))->withPath('/work/chats')->withQueryString();

        return ['chats' => $chats, 'q' => $q];
    }
}
