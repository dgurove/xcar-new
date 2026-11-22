<?php

namespace App\Http\Admin;

use App\Chats\Actions\DeleteChat;
use App\Chats\Actions\MarkChatRead;
use App\Chats\Actions\OpenChat;
use App\Chats\Chat;
use App\Chats\Direct;
use App\Chats\Presence;
use App\Http\Site\ChatController as Feed;
use App\Offers\Offer;
use App\Support\ListView;
use App\Support\Surface;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
        // Чужую личку читает только админ (`Chat::allows`); модератору по номеру — 404, как постороннему (22.11.2026).
        abort_unless($chat->allows($user), 404);
        $chat->load(['offer', 'offer.media', 'user', 'manager']);
        // Первое непрочитанное — по своей стороне: в чате площадки и своей личке; в чужой переписке не отмечаем ничего.
        $seen = $chat->myReadSeq($user);
        $firstUnread = $chat->canPost($user) && $seen < $chat->messages_count ? $seen + 1 : 0;
        $read($chat, $user);
        Presence::touch($chat, $user);
        $messages = $chat->messages()->with(['author', 'files', 'deletedBy'])->reorder('seq', 'desc')->limit(Feed::PAGE)->get()->reverse()->values();

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
        abort_unless($request->user()->canEditDeals(), 403);
        // Менеджер или супер-покупатель (у него сделки как у менеджера, `hasDeals`).
        // И тому, кто подтверждал машину (резерв, отклонённые) или чей покупатель проявил к ней интерес (19.11.2026, владелец:
        // «Тужиков передумал, хочу Роману перед передачей написать, а тут нет кнопочки чата»).
        abort_unless($user->hasDeals() && ($offer->worksWith($user) || Chat::where('offer_id', $offer->id)->where('user_id', $user->id)->exists()
            || $offer->bids()->where('user_id', $user->id)->exists()
            || $offer->interests()->whereHas('user', fn ($q) => $q->whereKey($user->id)->orWhere('manager_id', $user->id))->exists()), 404);

        return redirect('/work/chats/'.$open($offer, $user, greet: false)->id);
    }

    /**
     * Личка сотрудника (22.11.2026, `Chats\Direct`): есть — на её экран, нет — пустой экран, первое сообщение её заведёт.
     * Выбор из шторки «Написать» приходит `?user=` (форма GET).
     */
    public function with(Request $request, ?User $user = null)
    {
        $me = $request->user();
        $to = Direct::target($request, $user);
        abort_if($to === 'support', 404);
        if ($chat = Direct::between($me, $to)) {
            return redirect(Chat::base().'/'.$chat->id);
        }
        abort_unless(Direct::canStart($me, $to), 404);
        if (! $user) {
            return redirect(Chat::directPath($to->id));
        }

        return view('admin.chats.index', $this->list($request) + [
            'with' => $to, 'messages' => collect(), 'user' => $me, 'firstUnread' => 0, 'more' => false, 'current' => 'with',
        ]);
    }

    /** Спам и реклама в чате площадки — удалить целиком (только админ). Обратно — на список с тем же поиском. */
    public function destroy(Request $request, Chat $chat, DeleteChat $delete)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $delete($chat);
        $back = parse_url(url()->previous(), PHP_URL_QUERY);

        return redirect(Chat::base().($back ? '?'.$back : ''))->with('toast', 'Чат удалён');
    }

    /** Список чатов площадки и поиск из адреса; открытый чат его не меняет. Старый `?preset=` ничего не значит. */
    private function list(Request $request): array
    {
        $q = trim((string) $request->query('q'));
        // Заведённый кнопкой «Написать» и брошенный без сообщения — не строка списка.
        // В XCAR Chat (20.11.2026) у админа, который ещё и менеджер, — и его переписки с покупателями: приложение у него одно.
        // Свои лички (22.11.2026, `Chats\Direct`) — везде; админу при поиске — и чужие лички, только читать (владелец: «админы
        // читают все»; фильтра нет — «фильтры только мешают»).
        $me = $request->user();
        $own = Surface::current() === Surface::Chat;
        $people = $this->people($me, $q, $request);
        $chats = Chat::withLast()->where(fn ($w) => $w->whereNull('manager_id')
            ->orWhere(fn ($w) => $w->where(fn ($w) => $w->where('manager_id', $me->id)->orWhere('user_id', $me->id))->when(! $own, fn ($w) => $w->whereNull('offer_id')))
            ->when($q !== '' && $me->isAdmin(), fn ($w) => $w->orWhere(fn ($w) => $w->whereNull('offer_id')->whereNotNull('manager_id'))))
            ->where('messages_count', '>', 0)
            ->when($people->isNotEmpty(), fn ($w) => $w->whereNotIn('chats.id', $people->pluck('chat.id')->filter()))
            ->search($q, crm: true);
        // Непрочитанные сверху (по своей стороне: в своей личке участник читает `unread_for_user`), дальше свежие. Страницы
        // списка ведут на список, даже когда справа открыт чат.
        $chats = $chats->orderByRaw('(case when manager_id is null or manager_id = ? then unread_for_staff when user_id = ? then unread_for_user else 0 end) > 0 desc', [$me->id, $me->id])
            ->orderByDesc('last_message_at')
            ->paginate(ListView::perPage($request, ListView::PER_ROWS))->withPath(Chat::base())->withQueryString();

        return ['chats' => $chats, 'q' => $q, 'people' => $people];
    }

    /** Люди под запрос — первыми над чатами (`Direct::people`), только на первой странице. */
    private function people(User $me, string $q, Request $request): Collection
    {
        return $q !== '' && (int) $request->query('page', 1) <= 1 ? Direct::people($me, $q) : collect();
    }
}
