<?php

namespace App\Http\Admin;

use App\Chats\Actions\MarkChatRead;
use App\Chats\Chat;
use App\Mail\Scope;
use App\Mail\Thread;
use Illuminate\Http\Request;

class ChatController
{
    public const PRESETS = ['unread' => 'Непрочитанные', 'all' => 'Все', 'offers' => 'По предложениям', 'enquiries' => 'Обращения'];

    public function index(Request $request)
    {
        $preset = $request->query('preset', 'unread');
        $q = trim((string) $request->query('q'));
        $chats = Chat::with(['offer.brand', 'offer.model', 'offer.media', 'user'])
            ->when($preset === 'unread', fn ($c) => $c->where('unread_for_staff', '>', 0))
            ->when($preset === 'offers', fn ($c) => $c->whereNotNull('offer_id'))
            ->when($preset === 'enquiries', fn ($c) => $c->whereNull('offer_id'))
            ->when($q !== '', fn ($c) => $c->where(fn ($w) => $w->whereHas('user', fn ($u) => $u->whereRaw('lower(name) like ?', ['%'.mb_strtolower($q).'%'])->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $q).'%'))
                ->orWhereRaw('lower(guest_name) like ?', ['%'.mb_strtolower($q).'%'])
                ->orWhereHas('offer', fn ($o) => $o->where('number', (int) $q))))
            ->orderByDesc('last_message_at')->paginate(30)->withQueryString();

        return view('admin.chats.index', [
            'chats' => $chats, 'preset' => $preset, 'q' => $q,
            'unread' => Chat::where('unread_for_staff', '>', 0)->count(),
            'mailUnread' => Thread::where('unread_count', '>', 0)->whereHas('account', fn ($a) => $a->where('scope', Scope::Offers))->count(),
        ]);
    }

    public function show(Request $request, Chat $chat, MarkChatRead $read)
    {
        $chat->load(['offer.brand', 'offer.model', 'offer.media', 'user']);
        $read($chat, $request->user());

        return view('admin.chats.show', ['chat' => $chat, 'messages' => $chat->messages()->with(['author', 'files'])->get(), 'user' => $request->user()]);
    }
}
