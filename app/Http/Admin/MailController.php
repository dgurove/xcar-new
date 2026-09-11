<?php

namespace App\Http\Admin;

use App\Live\Stream;
use App\Mail\Account;
use App\Mail\Actions\LinkThread;
use App\Mail\Actions\MarkThreadRead;
use App\Mail\Actions\StoreOutboxFile;
use App\Mail\Attachment;
use App\Mail\BodyRenderer;
use App\Mail\Composer;
use App\Mail\Direction;
use App\Mail\Jobs\ParseMessage;
use App\Mail\Jobs\PushFlag;
use App\Mail\Jobs\SendMessage;
use App\Mail\Jobs\SyncAccount;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\SendState;
use App\Mail\Template;
use App\Mail\Thread;
use App\Offers\Offer;
use Illuminate\Http\Request;

/** Почта в админке: ветки, письмо, ответ. Ящики — по scope поверхности. */
class MailController
{
    public const PRESETS = ['all' => 'Все', 'unread' => 'Непрочитанные', 'files' => 'С вложениями', 'sent' => 'Отправленные', 'linked' => 'По офферам'];

    public function __construct(private Scope $scope = Scope::Offers, private string $base = '/admin/pochta') {}

    public function index(Request $request)
    {
        $accounts = Account::where('scope', $this->scope)->orderBy('title')->get();
        $preset = $request->query('preset', 'all');
        $slug = $request->query('yashchik');
        $q = trim((string) $request->query('q'));

        $threads = Thread::query()->with(['account', 'offer.brand', 'offer.model'])
            ->whereIn('account_id', $accounts->pluck('id'))
            ->when($slug, fn ($t) => $t->whereHas('account', fn ($a) => $a->where('slug', $slug)))
            ->when($q !== '', fn ($t) => $t->where(fn ($w) => $w->whereRaw('lower(subject) like ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('participants::text ilike ?', ['%'.$q.'%'])))
            ->where('messages_count', '>', 0);
        match ($preset) {
            'unread' => $threads->where('unread_count', '>', 0),
            'files' => $threads->where('has_attachments', true),
            'sent' => $threads->whereHas('messages', fn ($m) => $m->where('direction', Direction::Out)),
            'linked' => $threads->whereNotNull('offer_id'),
            default => null,
        };

        return view('admin.mail.index', [
            'threads' => $threads->orderByDesc('last_message_at')->paginate(30)->withQueryString(),
            'accounts' => $accounts,
            'preset' => $preset,
            'slug' => $slug,
            'q' => $q,
            'base' => $this->base,
            'unread' => Thread::whereIn('account_id', $accounts->pluck('id'))->where('unread_count', '>', 0)->count(),
            'chatsUnread' => \App\Chats\Chat::where('unread_for_staff', '>', 0)->count(),
        ]);
    }

    public function show(Request $request, Thread $thread, MarkThreadRead $markRead, BodyRenderer $renderer)
    {
        $this->guard($thread);
        $thread->load(['account', 'offer.brand', 'offer.model', 'messages.attachments', 'messages.addresses', 'messages.author']);
        $markRead($thread);
        $dark = $request->cookie('theme') === 'dark';

        return view('admin.mail.thread', [
            'thread' => $thread,
            'messages' => $thread->messages,
            'renderer' => $renderer,
            'documents' => fn (Message $m) => $renderer->document($m, $request->boolean('kartinki'), $dark),
            'base' => $this->base,
        ]);
    }

    public function compose(Request $request, Composer $composer)
    {
        $accounts = Account::where('scope', $this->scope)->where('is_active', true)->orderBy('title')->get();
        $account = $accounts->firstWhere('slug', $request->query('yashchik')) ?? $accounts->first();
        abort_unless($account, 404);
        $template = $request->query('shablon') ? Template::find($request->query('shablon')) : null;
        $offer = $request->query('offer') ? Offer::where('number', $request->query('offer'))->first() : null;
        $defaults = $composer->fresh($account, $template, $offer ? self::placeholders($offer) + ['to' => $offer->insurer?->email ?? ''] : []);

        return view('admin.mail.compose', ['account' => $account, 'accounts' => $accounts, 'thread' => null, 'parent' => null, 'defaults' => $defaults,
            'mode' => 'new', 'templates' => Template::where('scope', $this->scope)->orderBy('name')->get(), 'offer' => $offer, 'base' => $this->base]);
    }

    public function reply(Request $request, Thread $thread, Message $message, Composer $composer)
    {
        $this->guard($thread);
        abort_unless($message->thread_id === $thread->id, 404);
        $message->load(['account', 'addresses', 'attachments']);
        $mode = $request->query('rezhim', 'reply');
        $defaults = $mode === 'forward' ? $composer->forward($message) : $composer->reply($message, $mode === 'all');

        return view('admin.mail.compose', ['account' => $message->account, 'accounts' => collect([$message->account]), 'thread' => $thread, 'parent' => $message,
            'defaults' => $defaults, 'mode' => $mode, 'templates' => collect(), 'offer' => $thread->offer, 'base' => $this->base]);
    }

    public function send(Request $request, Composer $composer)
    {
        $data = $request->validate([
            'account' => ['required', 'exists:mail_accounts,slug'],
            'parent' => ['nullable', 'integer'],
            'thread' => ['nullable', 'integer'],
            'to' => ['required', 'string', 'max:1000'],
            'cc' => ['nullable', 'string', 'max:1000'],
            'bcc' => ['nullable', 'string', 'max:1000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:200000'],
            'files' => ['nullable', 'array'],
            'files.*' => ['string'],
            'forward' => ['nullable', 'array'],
            'forward.*' => ['integer'],
            'offer' => ['nullable', 'integer'],
        ]);
        $account = Account::where('slug', $data['account'])->where('scope', $this->scope)->firstOrFail();
        if (! $composer->emails($data['to'])) {
            return back()->withInput()->withErrors(['to' => 'Нужен хотя бы один адрес']);
        }
        $parent = ! empty($data['parent']) ? Message::with(['thread', 'attachments'])->where('account_id', $account->id)->find($data['parent']) : null;
        $thread = ! empty($data['thread']) ? Thread::where('account_id', $account->id)->find($data['thread']) : null;

        $message = $composer->create($account, $data, $parent, $request->user(), $thread);
        if (! empty($data['offer']) && $message->thread && ! $message->thread->offer_id) {
            $message->thread->update(['offer_id' => $data['offer']]);
        }

        return redirect("{$this->base}/{$message->thread_id}")->with('toast', 'Письмо в очереди');
    }

    public function file(Request $request, StoreOutboxFile $store)
    {
        $request->validate(['file' => ['required', 'file', 'max:25600']]);
        $path = $store($request->file('file'));

        return Stream::view('admin.mail.file-stream', ['path' => $path, 'name' => $request->file('file')->getClientOriginalName()]);
    }

    public function attachment(Attachment $attachment)
    {
        $attachment->load('message.account');
        abort_unless($attachment->message->account->scope === $this->scope || auth()->user()->isStaff(), 404);
        $contents = $attachment->contents();
        abort_if($contents === null, 404);
        $inline = $attachment->isImage() || $attachment->isPdf();

        return response($contents, 200, [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($attachment->filename),
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function unread(Thread $thread, MarkThreadRead $markRead)
    {
        $this->guard($thread);
        $markRead($thread, false);

        return redirect($this->base)->with('toast', 'Не прочитано');
    }

    public function flag(Message $message)
    {
        $message->forceFill(['is_flagged' => ! $message->is_flagged])->save();
        PushFlag::dispatch($message->id, '\\Flagged', $message->is_flagged);

        return back();
    }

    public function link(Request $request, Thread $thread, LinkThread $link)
    {
        $this->guard($thread);
        $number = (int) preg_replace('/\D/', '', (string) $request->input('number'));
        if ($number === 0) {
            $thread->update(['offer_id' => null]);

            return back()->with('toast', 'Отвязано');
        }
        $offer = Offer::where('number', $number)->first();
        if (! $offer) {
            return back()->withErrors(['number' => 'Нет такого оффера']);
        }
        $link($thread, $offer);

        return back()->with('toast', "Привязано к № {$offer->number}");
    }

    public function reparse(Message $message)
    {
        ParseMessage::dispatch($message->id);

        return back()->with('toast', 'Разбор поставлен в очередь');
    }

    public function resend(Message $message)
    {
        abort_unless($message->isOutgoing() && $message->send_state === SendState::Failed, 404);
        $message->forceFill(['send_state' => SendState::Queued, 'send_error' => null])->save();
        SendMessage::dispatch($message->id);

        return back()->with('toast', 'Отправляем снова');
    }

    public function sync(Request $request)
    {
        foreach (Account::where('scope', $this->scope)->where('is_active', true)->get() as $account) {
            SyncAccount::dispatch($account->id);
        }

        return back()->with('toast', 'Проверяем ящики');
    }

    /** Подстановки шаблона из оффера. */
    public static function placeholders(Offer $offer): array
    {
        $offer->loadMissing(['brand', 'model', 'insurer', 'deal.buyer']);

        return [
            'number' => $offer->number,
            'car' => $offer->titleWithYear(),
            'vin' => $offer->vin ?? '',
            'claim_ref' => $offer->claim_ref ?? '',
            'price' => $offer->deal?->amount ? number_format($offer->deal->amount, 0, '', ' ').' ₽' : ($offer->asking_price ? number_format($offer->asking_price, 0, '', ' ').' ₽' : ''),
            'manager' => $offer->deal?->buyer?->name ?? '',
            'insurer' => $offer->insurer?->name ?? '',
            'today' => now()->translatedFormat('j F Y'),
        ];
    }

    private function guard(Thread $thread): void
    {
        abort_unless($thread->account->scope === $this->scope, 404);
    }
}
