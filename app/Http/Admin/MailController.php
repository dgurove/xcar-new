<?php

namespace App\Http\Admin;

use App\Billing\ChargeKind;
use App\Billing\Documents\StorageActPdf;
use App\Billing\Invoice;
use App\Live\Stream;
use App\Mail\Account;
use App\Mail\Actions\ArchiveThread;
use App\Mail\Actions\LinkThread;
use App\Mail\Actions\MarkThreadRead;
use App\Mail\Actions\PromoteCandidate;
use App\Mail\Actions\StoreOutboxFile;
use App\Mail\Attachment;
use App\Mail\BodyRenderer;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Composer;
use App\Mail\Direction;
use App\Mail\Extraction\Intent;
use App\Mail\Extraction\Keys;
use App\Mail\Jobs\ExtractCandidate;
use App\Mail\Jobs\ParseMessage;
use App\Mail\Jobs\PushFlag;
use App\Mail\Jobs\SendMessage;
use App\Mail\Jobs\SyncAccount;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\SendState;
use App\Mail\Template;
use App\Mail\Thread;
use App\Media\PhotoIngest;
use App\Offers\Offer;
use App\Park\Actions\MarkDoc;
use App\Park\DocKind;
use App\Park\DocState;
use App\Park\Documents\ActPdf;
use App\Park\EventType;
use App\Park\InspectionKind;
use App\Park\PhotoStage;
use App\Park\Vehicle;
use App\Vendors\ContactRole;
use App\Vendors\Vendor;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Почта: Входящие · Ждут ответа · Прочее · Отправленные · Архив (ветка там, где её последнее письмо, как в Gmail).
 * Во «Входящих» секции по ТС (CRM — по предложениям) и кандидатам «Из писем», письма вендоров без тождества —
 * последней секцией; автоответы, рассылки и не-вендоры — в «Прочее». Строка несёт аватар отправителя, вендора и смысл
 * последнего письма тегом. Фильтры (вендор, смысл, непрочитанные, с файлами) и поиск — плоский список по всем письмам.
 */
class MailController
{
    /** Пилюли — дела, а не письма: сначала то, что требует нас, дальше вся переписка и хвосты. */
    public const BOXES = ['attention' => 'Требуют внимания', 'all' => 'Все', 'other' => 'Прочее', 'sent' => 'Отправленные', 'archive' => 'Архив'];

    public const SORTS = ['fresh' => 'Свежие', 'waiting' => 'Дольше ждут'];

    public const GROUPS_PER_PAGE = 30;

    public const ROWS_PER_PAGE = 50;

    public function __construct(private Scope $scope = Scope::Offers, private string $base = '/work/mail') {}

    /** Направление и смысл последнего письма ветки — колонки, их держит `Threads::refresh` (было подзапросом на строку). */
    private const LAST_DIRECTION = 'mail_threads.last_direction';

    private const LAST_INTENT = 'mail_threads.last_intent';

    /**
     * «Прочее» — не про машину: без ТС, кандидата и предложения, и при этом либо не вендор, либо автоответ,
     * либо бухгалтерия (акты, счета, сверка — это переписка бухгалтерий, а не дело по машине).
     */
    private function noise($query): void
    {
        $query->whereNull('vehicle_id')->whereNull('candidate_id')->whereNull('offer_id')
            ->where(fn ($w) => $w->whereNull('vendor_id')->orWhereRaw(self::LAST_INTENT." in ('auto', 'billing')"));
    }

    private function notNoise($query): void
    {
        $query->where(fn ($w) => $w->whereNotNull('vehicle_id')->orWhereNotNull('candidate_id')->orWhereNotNull('offer_id')
            ->orWhere(fn ($v) => $v->whereNotNull('vendor_id')->whereRaw('coalesce('.self::LAST_INTENT.", '') not in ('auto', 'billing')")));
    }

    /**
     * Дело требует нас, если хоть что-то из трёх: цепочка «Из писем» ещё не заведена; у ветки висит вопрос
     * (`needs_reply_at` — одно правило на почту, дело ТС и ленту); письмо-заявка от вендора, которое парсер
     * не привязал ни к чему — такое заводят руками из окна ветки.
     */
    private function attention($query, bool $park): void
    {
        $own = $park ? 'vehicle_id' : 'offer_id';
        $query->whereNotNull('needs_reply_at')
            ->orWhere(fn ($w) => $w->whereNull($own)->whereIn('candidate_id', Candidate::where('scope', $this->scope)->where('state', CandidateState::New)->select('id')))
            ->orWhere(fn ($w) => $w->whereNull('vehicle_id')->whereNull('offer_id')->whereNull('candidate_id')
                ->whereNotNull('vendor_id')->whereRaw(self::LAST_INTENT." = 'intake'"));
    }

    public function index(Request $request)
    {
        $accounts = Account::where('scope', $this->scope)->orderBy('title')->get();
        $box = array_key_exists($request->query('box', ''), self::BOXES) ? $request->query('box') : 'attention';
        // В делах, требующих нас, сверху самое старое: свежее и так на виду.
        $sort = array_key_exists($request->query('sort', ''), self::SORTS) ? $request->query('sort') : ($box === 'attention' ? 'waiting' : 'fresh');
        $q = trim((string) $request->query('q'));
        $filter = array_filter([
            'vendor' => (int) $request->query('vendor') ?: null,
            'intent' => Intent::tryFrom((string) $request->query('intent'))?->value,
            'unread' => $request->boolean('unread') ?: null,
            'files' => $request->boolean('files') ?: null,
        ]);
        $park = $this->scope === Scope::Park;
        $with = ['account', 'vendor', 'offer.brand', 'offer.model', 'vehicle.brand', 'vehicle.model', 'vehicle.yard', 'candidate.vendor', 'latestMessage.author', 'latestIncoming'];

        $all = Thread::query()->whereIn('account_id', $accounts->pluck('id'))->where('messages_count', '>', 0);
        // Ветки пилюли: по ним выбираются дела. Письма дела потом берутся все — действие требуется от дела,
        // а не от одного письма, поэтому секция не должна разваливаться под фильтром.
        $pick = fn (string $box) => match ($box) {
            'sent' => (clone $all)->whereNull('archived_at')->whereRaw(self::LAST_DIRECTION." = 'out'"),
            'archive' => (clone $all)->whereNotNull('archived_at'),
            'attention' => (clone $all)->whereNull('archived_at')->where(fn ($w) => $this->attention($w, $this->scope === Scope::Park)),
            'other' => (clone $all)->whereNull('archived_at')->where(fn ($w) => $this->noise($w)),
            default => (clone $all)->whereNull('archived_at')->where(fn ($w) => $this->notNoise($w)),
        };
        $threads = $pick($box);
        // Поиск — не фильтр: он сужает то, что уже выбрано пилюлей и фильтрами, и адрес не меняет.
        if ($q !== '') {
            $tsquery = self::tsquery($q);
            $threads->where(fn ($w) => $w
                ->when($tsquery, fn ($w, $ts) => $w->whereHas('messages', fn ($m) => $m->whereRaw("search @@ to_tsquery('russian', ?)", [$ts])))
                ->orWhereRaw('participants::text ilike ?', ['%'.$q.'%'])
                ->orWhereHas('attachments', fn ($a) => $a->where('filename', 'ilike', '%'.$q.'%'))
                ->when(Keys::fromQuery($q), fn ($w, $keys) => $w->orWhere(fn ($k) => $k->withAnyKey($keys))));
        }
        $threads->when($filter['vendor'] ?? null, fn ($t, $id) => $t->where('vendor_id', $id))
            ->when($filter['intent'] ?? null, fn ($t, $i) => $t->whereRaw(self::LAST_INTENT.' = ?', [$i]))
            ->when($filter['unread'] ?? null, fn ($t) => $t->where('unread_count', '>', 0))
            ->when($filter['files'] ?? null, fn ($t) => $t->where('has_attachments', true));
        $order = fn ($t) => $sort === 'waiting' ? $t->orderBy('last_message_at') : $t->orderByDesc('last_message_at');

        // Дело — машина, цепочка «Из писем» или предложение. Письмо, которому парсер не нашёл машину, — дело само
        // по себе: заголовка у такой секции нет (нечего писать), вендор стоит в строке.
        $own = $park ? "'v:' || vehicle_id::text" : "'o:' || offer_id::text";
        $group = "coalesce({$own}, 'c:' || candidate_id::text, 't:' || mail_threads.id::text)";
        $groups = (clone $threads)->selectRaw("{$group} as g, max(last_message_at) as at")->groupByRaw($group);
        $sort === 'waiting' ? $groups->orderBy('at') : $groups->orderByDesc('at');
        $keys = $groups->get()->pluck('g');
        $page = max(1, (int) $request->query('page', 1));
        $slice = $keys->forPage($page, self::GROUPS_PER_PAGE)->values();
        $paginator = new LengthAwarePaginator($slice, $keys->count(), self::GROUPS_PER_PAGE, $page, ['path' => $request->url(), 'query' => $request->query()]);
        // Письма дела — все, что лежат в том же ящике: пилюля выбрала дела, а не письма, и секция не разваливается.
        $rowsOf = $box === 'archive' ? (clone $all)->whereNotNull('archived_at') : (clone $all)->whereNull('archived_at');
        $rows = $slice->isEmpty() ? collect() : $order($rowsOf->with($with)
            ->whereRaw("{$group} in (".implode(',', array_fill(0, $slice->count(), '?')).')', $slice->all()))->get();
        $byGroup = $rows->groupBy(fn (Thread $t) => $park
            ? ($t->vehicle_id ? 'v:'.$t->vehicle_id : ($t->candidate_id ? 'c:'.$t->candidate_id : 't:'.$t->id))
            : ($t->offer_id ? 'o:'.$t->offer_id : ($t->candidate_id ? 'c:'.$t->candidate_id : 't:'.$t->id)));
        $sections = $slice->map(function (string $g) use ($byGroup) {
            $list = $byGroup->get($g, collect());
            $first = $list->first();

            return ['key' => $g, 'threads' => $list, 'vehicle' => str_starts_with($g, 'v:') ? $first?->vehicle : null,
                'offer' => str_starts_with($g, 'o:') ? $first?->offer : null, 'candidate' => str_starts_with($g, 'c:') ? $first?->candidate : null,
                // Чего ждёт дело: по нему рисуется полоска слева и кнопка в заголовке.
                'attention' => $list->contains(fn (Thread $t) => $t->needs_reply_at !== null) ? 'reply'
                    : (str_starts_with($g, 'c:') && $first?->candidate?->state === CandidateState::New ? 'register'
                        : (str_starts_with($g, 't:') && $first?->vendor_id && $first?->last_intent === Intent::Intake->value ? 'register' : null)),
            ];
        })->filter(fn ($s) => $s['threads']->isNotEmpty())->values();
        $threads = $paginator;

        // Список отдельным куском — им отвечает живой поиск, им же рисуется страница.
        $data = [
            'threads' => $threads,
            'sections' => $sections,
            'q' => $q,
            'filter' => $filter,
            'base' => $this->base,
            'park' => $park,
            'crm' => ! $park,
            'box' => $box,
        ];
        if ($request->header('X-List')) {
            return response()->view('admin.mail.list', $data);
        }

        // Счётчики пилюль — число дел, одним правилом на все (раньше «Входящие» считали ветки с непрочитанными,
        // а «Ждут ответа» — все ветки, и числа были несравнимы).
        $counts = [
            'attention' => (int) $pick('attention')->selectRaw('count(distinct '.$group.') as n')->value('n'),
            // В «Прочем» дело — само письмо: тождества у него нет, собирать нечего.
            'other' => $pick('other')->count(),
        ];

        return view('admin.mail.index', $data + [
            'sort' => $sort,
            'accounts' => $accounts,
            'counts' => array_filter($counts),
            'vendors' => Vendor::whereIn('id', (clone $all)->whereNotNull('vendor_id')->distinct()->pluck('vendor_id'))->orderBy('name')->pluck('name', 'id'),
            // ?window=id — ссылка на ветку: список с открытым окном этой ветки.
            'window' => $request->query('window') ? "{$this->base}/".(int) $request->query('window').'/window' : null,
        ]);
    }

    /**
     * Запрос для полнотекста. `websearch_to_tsquery` ищет только целые слова, а при наборе последнее
     * слово почти всегда неполное — оно уходит с префиксом, и «колбас» находит «Колбасина». Строка
     * разбирается здесь, а не в SQL: `to_tsquery` падает на любом постороннем знаке.
     */
    private static function tsquery(string $q): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (! $words) {
            return null;
        }
        $last = array_key_last($words);

        return implode(' & ', array_map(fn ($w, $i) => $w.($i === $last ? ':*' : ''), $words, array_keys($words)));
    }

    /** «Всё в архив» у «Прочего»: автоответы и рассылки уходят из входящих разом. */
    public function archiveOther(ArchiveThread $archive)
    {
        $accounts = Account::where('scope', $this->scope)->pluck('id');
        $threads = Thread::whereIn('account_id', $accounts)->whereNull('archived_at')->whereRaw(self::LAST_DIRECTION." = 'in'")->where(fn ($w) => $this->noise($w))->get();
        foreach ($threads as $thread) {
            $archive($thread);
        }

        return redirect($this->base)->with('toast', 'В архиве: '.$threads->count());
    }

    /** «Заявка ›» (CRM: «Предложение ›») у нераспознанного письма: кандидат из ветки как есть, дальше обычная дверь заведения. */
    public function candidate(Request $request, Thread $thread)
    {
        $this->guard($thread);
        $message = $thread->messages()->with(['thread', 'account'])->where('direction', Direction::In)->orderByDesc('date_at')->first()
            ?? $thread->messages()->with(['thread', 'account'])->orderByDesc('date_at')->first();
        abort_if(! $message, 404);
        $candidate = $thread->candidate ?? ExtractCandidate::run($message, force: true, quiet: true);
        abort_if(! $candidate, 404);
        if ($this->scope === Scope::Park) {
            return redirect('/requests/new?candidate='.$candidate->id);
        }
        $offer = app(PromoteCandidate::class)($candidate, $request->user());

        return redirect("/offers/{$offer->number}")->with('toast', $offer->wasRecentlyCreated ? 'Черновик заведён, фото подтягиваются' : 'Письма привязаны к предложению');
    }

    /** В архив ↔ вернуть. Из списка (свайп) — строка исчезает стримом; из окна — окно перечитывается. */
    public function archive(Request $request, Thread $thread, ArchiveThread $archive)
    {
        $this->guard($thread);
        $thread->archived_at ? $archive->restoreWithCandidate($thread) : $archive($thread);
        if ($request->header('Turbo-Frame') === 'letters-frame') {
            return redirect("{$this->base}/{$thread->id}/window");
        }
        if (str_contains((string) $request->header('Accept'), 'turbo-stream')) {
            return response()->view('admin.mail.thread-row-stream', ['thread' => $thread])->header('Content-Type', 'text/vnd.turbo-stream.html');
        }

        return back()->with('toast', $thread->archived_at ? 'В архиве' : 'Снова во входящих');
    }

    /** Окно ветки (фрейм letters-frame в x-mail.window): все письма целиком, «Ответить» под каждым, привязка. */
    public function window(Request $request, Thread $thread, MarkThreadRead $markRead)
    {
        $this->guard($thread);
        $thread->load(['account', 'offer.brand', 'offer.model', 'vehicle.brand', 'vehicle.model', 'messages.attachments', 'messages.addresses', 'messages.author']);
        if (! str_contains($request->header('Sec-Purpose', $request->header('X-Sec-Purpose', '')), 'prefetch')) {
            $markRead($thread);
        }

        return view('admin.mail.window', ['thread' => $thread, 'base' => $this->base]);
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

    public function compose(Request $request, Composer $composer, StoreOutboxFile $outbox, ActPdf $act, StorageActPdf $actPdf)
    {
        $accounts = Account::where('scope', $this->scope)->where('is_active', true)->orderBy('title')->get();
        $account = $accounts->firstWhere('slug', $request->query('account')) ?? $accounts->first();
        abort_unless($account, 404);
        $template = $request->query('template') ? Template::find($request->query('template')) : null;
        $offer = $request->query('offer') ? Offer::where('number', $request->query('offer'))->first() : null;
        $vehicle = $request->query('car') ? Vehicle::find($request->query('car')) : null;
        // Счёт за хранение вендору: ТС счёта, шаблон «Счёт за хранение», PDF счёта и акта, адресат — бухгалтерия вендора.
        $invoice = $request->query('invoice') ? Invoice::with(['vehicle', 'party'])->find($request->query('invoice')) : null;
        if ($invoice?->vehicle) {
            $vehicle = $invoice->vehicle;
            $template ??= Template::park('invoice');
        }
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
            $values = self::vehiclePlaceholders($vehicle) + ['to' => $invoice
                ? ($vehicle->vendor?->email(ContactRole::Accounting, ContactRole::Storage, ContactRole::Claims) ?? $parent?->replyToAddress() ?? '')
                : ($parent?->replyToAddress() ?? $vehicle->vendor?->email(ContactRole::Storage, ContactRole::Claims) ?? '')];
        }
        $defaults = $composer->fresh($account, $template, $values);
        if ($parent) {
            $defaults['subject'] = $defaults['subject'] ?: 'Re: '.$parent->subject;
        }
        // Письмо о принятой ТС уходит с актом и фото приёма (Альфа и Совкомбанк просят именно их); лишнее снимают в форме.
        // `act=release` — акт выдачи (в том числе с отказом от получения, когда ТС осталась).
        if ($invoice && ! $request->old()) {
            foreach (['file', 'act'] as $collection) {
                if ($media = $invoice->getFirstMedia($collection)) {
                    $defaults['files'][] = $outbox->put($media->file_name, file_get_contents($media->getPath()));
                }
            }
            if (! $invoice->getFirstMedia('act') && $invoice->kind === ChargeKind::Storage) {
                $defaults['files'][] = $outbox->put('akt-hraneniya-'.$invoice->number.'-'.$invoice->year.'.pdf', $actPdf->render($invoice));
            }
        } elseif ($vehicle?->accepted_at && ! $request->old()) {
            $intake = $request->query('act') ? $request->query('act') !== 'release' : ! $vehicle->released_at;
            $defaults['files'][] = $outbox->put($act->filename($vehicle, $intake), $act->render($vehicle, $intake));
            $stage = $intake ? PhotoStage::Intake : PhotoStage::Release;
            // Сначала снятое на парковке, потом приехавшее из писем: вендору нужны наши кадры, а не его же.
            $shots = $vehicle->photos()->filter(fn ($m) => PhotoStage::of($m) === $stage)
                ->sortBy(fn ($m) => $m->getCustomProperty('source') === 'mail' ? 1 : 0)->values();
            foreach (($shots->isNotEmpty() ? $shots : $vehicle->visiblePhotos())->take(12) as $i => $m) {
                $contents = file_get_contents($m->getPath());
                // Во вложение уходит ужатый кадр, а `sha` у него от исходника: запоминаем отпечаток отправленного,
                // иначе это же письмо из «Отправленных» принесёт наши фото обратно вторым экземпляром.
                if ($m->getCustomProperty('sent_sha') !== ($sha = hash('sha256', $contents))) {
                    $m->setCustomProperty('sent_sha', $sha)->save();
                }
                $defaults['files'][] = $outbox->put(($intake ? 'priem' : 'vydacha').'-'.($i + 1).'.'.pathinfo($m->file_name, PATHINFO_EXTENSION), $contents);
            }
        }

        // В окне писем (Turbo-Frame) — тот же редактор во фрейме, без оболочки.
        return view($request->header('Turbo-Frame') ? 'admin.mail.compose-frame' : 'admin.mail.compose', ['frame' => $request->header('Turbo-Frame'), 'account' => $account, 'accounts' => $accounts, 'thread' => $thread, 'parent' => $parent, 'defaults' => $defaults, 'back' => $request->query('back') ?? ($invoice ? '/money/invoices/'.$invoice->id : null), 'invoice' => $invoice,
            'mode' => 'new', 'templates' => Template::where('scope', $this->scope)->orderBy('name')->get(), 'offer' => $offer, 'vehicle' => $vehicle, 'base' => $this->base]);
    }

    public function reply(Request $request, Thread $thread, Message $message, Composer $composer)
    {
        $this->guard($thread);
        abort_unless($message->thread_id === $thread->id, 404);
        // «Отмена» в ленте: вместо редактора снова кнопка в том же фрейме.
        if ($request->boolean('cancel') && str_starts_with((string) $request->header('Turbo-Frame'), 'reply')) {
            return view('admin.mail.reply-frame', ['message' => $message, 'base' => $this->base, 'frame' => $request->header('Turbo-Frame')]);
        }
        $message->load(['account', 'addresses', 'attachments']);
        $mode = $request->query('mode', 'reply');
        $defaults = $mode === 'forward' ? $composer->forward($message) : $composer->reply($message, $mode === 'all');

        return view($request->header('Turbo-Frame') ? 'admin.mail.compose-frame' : 'admin.mail.compose', ['frame' => $request->header('Turbo-Frame'), 'account' => $message->account, 'accounts' => collect([$message->account]), 'thread' => $thread, 'parent' => $message,
            'defaults' => $defaults, 'mode' => $mode, 'templates' => collect(), 'offer' => $thread->offer, 'vehicle' => $thread->vehicle, 'base' => $this->base]);
    }

    public function send(Request $request, Composer $composer, MarkDoc $mark)
    {
        // Из окна писем форма отвечает во фрейм: ошибки — обратно в редактор (retry), успех — ветка в окне.
        $inWindow = $request->header('Turbo-Frame') === 'letters-frame';
        $retry = $inWindow && preg_match('#^/(?!/)#', (string) $request->input('retry')) ? $request->input('retry') : null;
        try {
            $data = $this->sendRules($request);
        } catch (ValidationException $e) {
            if ($retry) {
                return redirect($retry)->withErrors($e->errors())->withInput();
            }
            throw $e;
        }
        $account = Account::where('slug', $data['account'])->where('scope', $this->scope)->firstOrFail();
        if (! $composer->emails($data['to'])) {
            return redirect($retry ?: url()->previous())->withInput()->withErrors(['to' => 'Нужен хотя бы один адрес']);
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
        // Ушёл акт и фото — бумаги «акты» и «фото» вендору отмечаются отправленными этим письмом, в ленте ТС — отчёт.
        if (! empty($data['vehicle']) && ($vehicle = Vehicle::find($data['vehicle']))) {
            $sent = collect($message->attachments()->pluck('filename'));
            $act = $sent->first(fn ($f) => str_starts_with($f, 'akt-'));
            $kinds = array_filter([$act ? DocKind::HandoverAct : null, $act ? DocKind::StorageAct : null, $sent->contains(fn ($f) => preg_match('/^(priem|vydacha)-\d+\./', $f)) ? DocKind::Photos : null]);
            foreach ($vehicle->docs()->where('direction', 'out')->where('state', DocState::Pending)->whereIn('kind', $kinds)->get() as $doc) {
                $mark($doc, $request->user(), DocState::Sent, threadId: $message->thread_id);
            }
            if ($act) {
                $vehicle->log(EventType::ReportSent, $request->user(), ['what' => str_starts_with($act, 'akt-vydachi') ? 'Акт выдачи' : 'Акт приёма', 'thread' => $message->thread_id]);
            }
            // Ушёл счёт — отметка на нём и в ленте ТС.
            if (! empty($data['invoice']) && ($invoice = Invoice::where('vehicle_id', $vehicle->id)->find($data['invoice']))) {
                $invoice->update(['sent_at' => now()]);
                $vehicle->log(EventType::ReportSent, $request->user(), ['what' => 'Счёт '.$invoice->label(), 'thread' => $message->thread_id]);
            }
        }

        if ($inWindow) {
            return redirect("{$this->base}/{$message->thread_id}/window")->with('toast', 'Письмо в очереди');
        }

        return redirect($data['back'] ?? "{$this->base}/{$message->thread_id}")->with('toast', 'Письмо в очереди');
    }

    public function file(Request $request, StoreOutboxFile $store)
    {
        $request->validate(['file' => ['required', 'file', 'max:25600']]);
        $path = $store($request->file('file'));

        return Stream::view('admin.mail.file-stream', ['path' => $path, 'name' => $request->file('file')->getClientOriginalName()]);
    }

    public function attachment(Request $request, Attachment $attachment, PhotoIngest $photos)
    {
        $attachment->load('message.account');
        abort_unless($attachment->message->account->scope === $this->scope || auth()->user()->isStaff(), 404);
        // Файл — из outbox, из закреплённых или из ящика через кэш; отдаётся с диска, не через память.
        $file = $attachment->file();
        abort_if($file === null, 404, 'Файла нет: письмо удалено из ящика');
        // ?thumb — миниатюра картинки для сетки в письме: считается раз, живёт в cache/mail (storage:gc чистит по сроку).
        if ($request->boolean('thumb') && $attachment->isImage() && $attachment->mime !== 'image/svg+xml') {
            $thumb = Storage::disk('cache')->path("mail/thumb-{$attachment->id}.webp");
            if (! is_file($thumb)) {
                try {
                    $made = $photos->shrink($file, 320);
                    rename($made, $thumb);
                } catch (\Throwable) {
                    // Не пережалось (битый файл) — отдаём как есть.
                }
            }
            if (is_file($thumb)) {
                return response()->file($thumb, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=86400']);
            }
        }
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

    /** Исходное письмо целиком (фрейм body-{id} в ленте): тело в песочнице iframe, ?images=1 — с картинками из сети. */
    public function body(Request $request, Message $message, BodyRenderer $renderer)
    {
        $this->guard($message->thread);

        return view('admin.mail.body-frame', ['message' => $message, 'base' => $this->base, 'renderer' => $renderer,
            'document' => $renderer->document($message, $request->boolean('images'), $this->base), 'images' => $request->boolean('images')]);
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
        // Из окна писем форма отвечает во фрейм — назад в то же окно.
        $back = fn () => $request->header('Turbo-Frame') === 'letters-frame' ? redirect("{$this->base}/{$thread->id}/window") : back();
        if ($this->scope === Scope::Park) {
            $vehicle = $request->input('vehicle_id') ? Vehicle::find($request->input('vehicle_id')) : null;
            $vehicle ? $link($thread, $vehicle) : $link->unlink($thread);

            return $back()->with('toast', $vehicle ? 'Привязано' : 'Отвязано');
        }
        $number = (int) preg_replace('/\D/', '', (string) $request->input('number'));
        if ($number === 0) {
            $link->unlink($thread);

            return $back()->with('toast', 'Отвязано');
        }
        $offer = Offer::where('number', $number)->first();
        if (! $offer) {
            return $back()->withErrors(['number' => 'Нет такого предложения']);
        }
        $link($thread, $offer);

        return $back()->with('toast', "Привязано к № {$offer->number}");
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

    private function sendRules(Request $request): array
    {
        return $request->validate([
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
            'back' => ['nullable', 'string', 'max:255', 'regex:#^/(?!/)#'], // свой путь, не //host
            'invoice' => ['nullable', 'integer'],
        ], [], ['to' => 'Кому', 'cc' => 'Копия', 'subject' => 'Тема', 'body' => 'Письмо']);
    }

    private function guard(Thread $thread): void
    {
        abort_unless($thread->account->scope === $this->scope, 404);
    }
}
