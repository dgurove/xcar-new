<?php

namespace App\Http\Admin;

use App\Chats\Actions\MarkChatRead;
use App\Chats\Chat;
use App\Chats\Presence;
use App\Http\Site\ChatController as Feed;
use Illuminate\Http\Request;

/** Чаты площадки — отвечают сотрудники; переписки покупателей с менеджерами — только читаются. */
class ChatController
{
    public const PRESETS = ['unread' => 'Непрочитанные', 'all' => 'Все', 'offers' => 'По предложениям', 'enquiries' => 'Обращения', 'buyers' => 'Покупатели с менеджерами'];

    public function index(Request $request)
    {
        $preset = $request->query('preset', 'unread');
        $q = trim((string) $request->query('q'));
        $like = '%'.mb_strtolower($q).'%';
        $chats = Chat::with(['offer.brand', 'offer.model', 'offer.media', 'user', 'manager'])
            ->when($preset === 'buyers', fn ($c) => $c->whereNotNull('manager_id'), fn ($c) => $c->when($preset !== 'all', fn ($c) => $c->whereNull('manager_id')))
            ->when($preset === 'unread', fn ($c) => $c->where('unread_for_staff', '>', 0))
            ->when($preset === 'offers', fn ($c) => $c->whereNotNull('offer_id'))
            ->when($preset === 'enquiries', fn ($c) => $c->whereNull('offer_id'))
            ->when($q !== '', fn ($c) => $c->where(fn ($w) => $w->whereHas('user', fn ($u) => $u->whereRaw('lower(name) like ?', [$like])->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $q).'%'))
                ->orWhereHas('manager', fn ($u) => $u->whereRaw('lower(name) like ?', [$like]))
                ->orWhereRaw('lower(guest_name) like ?', [$like])
                ->orWhereHas('offer', fn ($o) => $o->where('number', (int) $q))))
            ->orderByDesc('last_message_at')->paginate(30)->withQueryString();

        return view('admin.chats.index', [
            'chats' => $chats, 'preset' => $preset, 'q' => $q,
            'unread' => Chat::whereNull('manager_id')->where('unread_for_staff', '>', 0)->count(),
        ]);
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

        return view('admin.chats.show', [
            'chat' => $chat, 'messages' => $messages, 'user' => $user, 'firstUnread' => $firstUnread,
            'more' => $messages->isNotEmpty() && $messages->first()->seq > 1,
        ]);
    }
}
