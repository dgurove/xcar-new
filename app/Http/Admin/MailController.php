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
use App\Park\Actions\MarkDoc;
use App\Park\DocKind;
use App\Park\DocState;
use App\Park\Documents\ActPdf;
use App\Park\InspectionKind;
use App\Park\Vehicle;
use App\Support\ListPrefs;
use App\Support\ListView;
use App\Vendors\ContactRole;
use Illuminate\Http\Request;

/** Почта в админке: ветки, письмо, ответ. Ящики — по scope поверхности. */
class MailController
{
    public const PRESETS = ['all' => 'Все', 'unread' => 'Непрочитанные', 'files' => 'С вложениями', 'sent' => 'Отправленные', 'linked' => 'По предложениям'];

    public const SORTS = ['fresh' => 'Свежие', 'unanswered' => 'Давно без ответа', 'unread' => 'Непрочитанные первыми'];

    /** Пресеты по поверхности: на стоянке «привязанные» — к ТС, не к предложениям. */
    public function presets(): array
    {
        return $this->scope === Scope::Park ? array_replace(self::PRESETS, ['linked' => 'По ТС']) : self::PRESETS;
    }

    public function __construct(private Scope $scope = Scope::Offers, private string $base = '/work/mail') {}

    public function index(Request $request)
    {
        ListPrefs::sync($request, $this->scope->value.'-mail');
        $accounts = Account::where('scope', $this->scope)->orderBy('title')->get();
        $preset = $request->query('preset', 'all');
        $slug = $request->query('account');
        $q = trim((string) $request->query('q'));
        $sort = array_key_exists($request->query('sort', ''), self::SORTS) ? $request->query('sort') : 'fresh';

        $threads = Thread::query()->with(['account', 'offer.brand', 'offer.model', 'vehicle.brand', 'vehicle.model'])
            ->whereIn('account_id', $accounts->pluck('id'))
            ->when($slug, fn ($t) => $t->whereHas('account', fn ($a) => $a->where('slug', $slug)))
            ->when($request->query('car'), fn ($t, $id) => $t->where('vehicle_id', $id))
            ->when($q !== '', fn ($t) => $t->where(fn ($w) => $w->whereRaw('lower(subject) like ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('participants::text ilike ?', ['%'.$q.'%'])))
            ->where('messages_count', '>', 0);
        match ($preset) {
            'unread' => $threads->where('unread_count', '>', 0),
            'files' => $threads->where('has_attachments', true),
            'sent' => $threads->whereHas('messages', fn ($m) => $m->where('direction', Direction::Out)),
            'linked' => $this->scope === Scope::Park ? $threads->whereNotNull('vehicle_id') : $threads->whereNotNull('offer_id'),
            default => null,
        };

        match ($sort) {
            // «Давно без ответа» — последнее письмо ветки входящее: сначала те, кому давно не отвечали.
            'unanswered' => $threads->whereExists(fn ($s) => $s->selectRaw('1')->from('mail_messages as m')->whereColumn('m.thread_id', 'mail_threads.id')->where('m.direction', Direction::In->value)
                ->whereRaw('m.date_at = (select max(date_at) from mail_messages where thread_id = mail_threads.id)'))->orderBy('last_message_at'),
            'unread' => $threads->orderByDesc('unread_count')->orderByDesc('last_message_at'),
            default => $threads->orderByDesc('last_message_at'),
        };

        return view('admin.mail.index', [
            'threads' => $threads->paginate(ListView::perPage($request, ListView::PER_ROWS))->withQueryString(),
            'sort' => $sort,
            'accounts' => $accounts,
            'preset' => $preset,
            'slug' => $slug,
            'q' => $q,
            'base' => $this->base,
            'unread' => Thread::whereIn('account_id', $accounts->pluck('id'))->where('unread_count', '>', 0)->count(),
            'presets' => $this->presets(),
            'car' => $request->query('car') ? Vehicle::with(['brand', 'model'])->find($request->query('car')) : null,
        ]);
    }

    public function show(Request $request, Thread $thread, MarkThreadRead $markRead, BodyRenderer $renderer)
    {
        $this->guard($thread);
        $thread->load(['account', 'offer.brand', 'offer.model', 'vehicle.brand', 'vehicle.model', 'messages.attachments', 'messages.addresses', 'messages.author']);
        // Касание строки — ещё не чтение: префетч Turbo идёт с Sec-Purpose: prefetch.
        if (! str_contains($request->header('Sec-Purpose', $request->header('X-Sec-Purpose', '')), 'prefetch')) {
            $markRead($thread);
        }

        return view('admin.mail.thread', [
            'thread' => $thread,
            'messages' => $thread->messages,
            'renderer' => $renderer,
            'documents' => fn (Message $m) => $renderer->document($m, $request->boolean('images'), $this->base),
            'base' => $this->base,
        ]);
    }

    public function compose(Request $request, Composer $composer, StoreOutboxFile $outbox, ActPdf $act)
    {
        $accounts = Account::where('scope', $this->scope)->where('is_active', true)->orderBy('title')->get();
        $account = $accounts->firstWhere('slug', $request->query('account')) ?? $accounts->first();
        abort_unless($account, 404);
        $template = $request->query('template') ? Template::find($request->query('template')) : null;
        $offer = $request->query('offer') ? Offer::where('number', $request->query('offer'))->first() : null;
        $vehicle = $request->query('car') ? Vehicle::find($request->query('car')) : null;
        $thread = null;
        $parent = null;
        $values = [];
        if ($offer) {
            // Адресат — ответственный по убытку, иначе контакт вендора по реализации или убыткам; кого он держит в копии — в cc.
            $offer->loadMissing('vendor.contacts');
            $values = self::placeholders($offer) + [
                'to' => $offer->contact_email ?? $offer->vendor?->email(ContactRole::Sales, ContactRole::Claims) ?? '',
                'cc' => implode(', ', $offer->vendor?->ccEmails() ?? []),
            ];
            if (! $request->query('account') && $offer->vendor?->mail_account_id) {
                $account = $accounts->firstWhere('id', $offer->vendor->mail_account_id) ?? $account;
            }
        }
        if ($vehicle) {
            // Письмо о машине отвечает в ту ветку, которой приехала заявка.
            $thread = Thread::where('vehicle_id', $vehicle->id)->orderByDesc('last_message_at')->first();
            $parent = $thread?->messages()->where('direction', Direction::In)->orderByDesc('date_at')->first();
            $vehicle->loadMissing('vendor.contacts');
            $values = self::vehiclePlaceholders($vehicle) + ['to' => $parent?->replyToAddress() ?? $vehicle->vendor?->email(ContactRole::Storage, ContactRole::Claims) ?? ''];
        }
        $defaults = $composer->fresh($account, $template, $values);
        if ($parent) {
            $defaults['subject'] = $defaults['subject'] ?: 'Re: '.$parent->subject;
        }
        // Письмо о принятой ТС уходит с актом и фото приёма (Альфа и Совкомбанк просят именно их); лишнее снимают в форме.
        if ($vehicle?->accepted_at && ! $request->old()) {
            $intake = ! $vehicle->released_at;
            $defaults['files'][] = $outbox->put($act->filename($vehicle, $intake), $act->render($vehicle, $intake));
            $shots = $vehicle->photos()->filter(fn ($m) => $m->getCustomProperty('stage') === ($intake ? 'intake' : 'release'));
            foreach (($shots->isNotEmpty() ? $shots : $vehicle->visiblePhotos())->take(12) as $i => $m) {
                $defaults['files'][] = $outbox->put(($intake ? 'priem' : 'vydacha').'-'.($i + 1).'.'.pathinfo($m->file_name, PATHINFO_EXTENSION), file_get_contents($m->getPath()));
            }
        }

        return view('admin.mail.compose', ['account' => $account, 'accounts' => $accounts, 'thread' => $thread, 'parent' => $parent, 'defaults' => $defaults,
            'mode' => 'new', 'templates' => Template::where('scope', $this->scope)->orderBy('name')->get(), 'offer' => $offer, 'vehicle' => $vehicle, 'base' => $this->base]);
    }

    public function reply(Request $request, Thread $thread, Message $message, Composer $composer)
    {
        $this->guard($thread);
        abort_unless($message->thread_id === $thread->id, 404);
        $message->load(['account', 'addresses', 'attachments']);
        $mode = $request->query('mode', 'reply');
        $defaults = $mode === 'forward' ? $composer->forward($message) : $composer->reply($message, $mode === 'all');

        return view('admin.mail.compose', ['account' => $message->account, 'accounts' => collect([$message->account]), 'thread' => $thread, 'parent' => $message,
            'defaults' => $defaults, 'mode' => $mode, 'templates' => collect(), 'offer' => $thread->offer, 'vehicle' => $thread->vehicle, 'base' => $this->base]);
    }

    public function send(Request $request, Composer $composer, MarkDoc $mark)
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
            'vehicle' => ['nullable', 'integer'],
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
        if (! empty($data['vehicle']) && $message->thread && ! $message->thread->vehicle_id) {
            $message->thread->update(['vehicle_id' => $data['vehicle']]);
        }
        // Ушёл акт и фото — бумаги «акт хранения» и «фото» вендору отмечаются отправленными этим письмом.
        if (! empty($data['vehicle']) && ($vehicle = Vehicle::find($data['vehicle']))) {
            $sent = collect($message->attachments()->pluck('filename'));
            $kinds = array_filter([$sent->contains(fn ($f) => str_starts_with($f, 'akt-')) ? DocKind::StorageAct : null, $sent->contains(fn ($f) => preg_match('/^(priem|vydacha)-\d+\./', $f)) ? DocKind::Photos : null]);
            foreach ($vehicle->docs()->where('direction', 'out')->where('state', DocState::Pending)->whereIn('kind', $kinds)->get() as $doc) {
                $mark($doc, $request->user(), DocState::Sent, threadId: $message->thread_id);
            }
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
        // Файл — из outbox, из закреплённых или из ящика через кэш; отдаётся с диска, не через память.
        $file = $attachment->file();
        abort_if($file === null, 404, 'Файла нет: письмо удалено из ящика');
        // SVG — не картинка, а документ со скриптами: только на скачивание.
        $inline = ($attachment->isImage() && $attachment->mime !== 'image/svg+xml') || $attachment->isPdf();

        return response()->file($file, [
            'Content-Type' => $attachment->mime ?: 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($attachment->filename),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function unread(Thread $thread, MarkThreadRead $markRead)
    {
        $this->guard($thread);
        $markRead($thread, false);

        return redirect($this->base)->with('toast', 'Не прочитано');
    }

    /** Смахнули строку: прочитано ↔ не прочитано, ответ — та же строка стримом. */
    public function toggleRead(Request $request, Thread $thread, MarkThreadRead $markRead)
    {
        $this->guard($thread);
        $markRead($thread, (bool) $thread->unread_count);
        $thread->refresh()->load(['account', 'offer', 'vehicle']);

        return response()
            ->view('admin.mail.thread-row-stream', ['thread' => $thread, 'base' => $this->base, 'accounts' => null, 'slug' => null])
            ->header('Content-Type', 'text/vnd.turbo-stream.html');
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
        if ($this->scope === Scope::Park) {
            $vehicle = $request->input('vehicle_id') ? Vehicle::find($request->input('vehicle_id')) : null;
            $vehicle ? $link($thread, $vehicle) : $link->unlink($thread);

            return back()->with('toast', $vehicle ? 'Привязано' : 'Отвязано');
        }
        $number = (int) preg_replace('/\D/', '', (string) $request->input('number'));
        if ($number === 0) {
            $link->unlink($thread);

            return back()->with('toast', 'Отвязано');
        }
        $offer = Offer::where('number', $number)->first();
        if (! $offer) {
            return back()->withErrors(['number' => 'Нет такого предложения']);
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
        $offer->loadMissing(['brand', 'model', 'vendor', 'deal.buyer']);

        return [
            'number' => $offer->number,
            'car' => $offer->titleWithYear(),
            'vin' => $offer->vin ?? '',
            'claim_ref' => $offer->claim_ref ?? '',
            'price' => $offer->deal?->amount ? number_format($offer->deal->amount, 0, '', ' ').' ₽' : ($offer->asking_price ? number_format($offer->asking_price, 0, '', ' ').' ₽' : ''),
            'manager' => $offer->deal?->buyer?->name ?? '',
            'insurer' => $offer->vendor?->name ?? '',
            'today' => now()->translatedFormat('j F Y'),
        ];
    }

    /** Подстановки шаблона из машины на стоянке. */
    /** Повреждения — как в акте: из последнего осмотра при приёме, иначе с карточки ТС. */
    private static function damagesLine(Vehicle $vehicle): string
    {
        $inspection = $vehicle->lastInspection(InspectionKind::Intake);
        $zones = $inspection?->damages() ?: $vehicle->damages();
        $note = $inspection?->damage_note ?? $vehicle->damage_note;

        return $zones || $note ? trim(implode(', ', $zones).($note ? '. '.$note : ''), '. ') : 'не обнаружены';
    }

    public static function vehiclePlaceholders(Vehicle $vehicle): array
    {
        $vehicle->loadMissing(['brand', 'model', 'vendor', 'yard', 'inspections']);

        return [
            'ref' => $vehicle->ref ?? '',
            'car' => $vehicle->titleWithYear(),
            'vin' => $vehicle->vin ?? '',
            'plate' => $vehicle->plate ?? '',
            'yard' => $vehicle->yard?->name ?? '',
            'address' => $vehicle->yard?->address ?? '',
            'date' => ($vehicle->released_at ?? $vehicle->accepted_at)?->format('d.m.Y') ?? now()->format('d.m.Y'),
            'days' => (string) ($vehicle->daysStored() ?? ''),
            'damages' => self::damagesLine($vehicle),
            'client' => $vehicle->vendor?->name ?? '',
            'today' => now()->translatedFormat('j F Y'),
        ];
    }

    private function guard(Thread $thread): void
    {
        abort_unless($thread->account->scope === $this->scope, 404);
    }
}
