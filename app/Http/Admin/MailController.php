<?php

namespace App\Http\Admin;

use App\Billing\Actions\FixClosingDocument;
use App\Billing\Acts\RegistryAct;
use App\Billing\ChargeKind;
use App\Billing\Closing;
use App\Billing\Documents\InvoicePdf;
use App\Billing\Documents\PassportPdf;
use App\Billing\Documents\StorageActPdf;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\PartyKind;
use App\Live\Stream;
use App\Mail\Account;
use App\Mail\Actions\ArchiveThread;
use App\Mail\Actions\DropFailed;
use App\Mail\Actions\FreezeMessages;
use App\Mail\Actions\LinkThread;
use App\Mail\Actions\MarkMessageRead;
use App\Mail\Actions\MarkThreadRead;
use App\Mail\Actions\PromoteCandidate;
use App\Mail\Actions\StoreOutboxFile;
use App\Mail\AddressKind;
use App\Mail\Attachment;
use App\Mail\Boxes;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Chains\ChainBuilder;
use App\Mail\Chains\NodeTitle;
use App\Mail\Composer;
use App\Mail\Direction;
use App\Mail\Extraction\ArchivePhotoExtractor;
use App\Mail\Extraction\Intent;
use App\Mail\Jobs\ImportCandidateFiles;
use App\Mail\Jobs\ParseMessage;
use App\Mail\Jobs\PushFlag;
use App\Mail\Jobs\SendMessage;
use App\Mail\Message;
use App\Mail\Scope;
use App\Mail\Senders;
use App\Mail\SendState;
use App\Mail\Sync;
use App\Mail\Template;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Media\PhotoIngest;
use App\Media\PhotosPdf;
use App\Media\Thumb;
use App\Offers\Actions\AutoOffer;
use App\Offers\Actions\MarkSupplierRequestSent;
use App\Offers\Actions\LogExtraRequest;
use App\Offers\Documents\PaymentProofPdf;
use App\Offers\Documents\SignedContractPdf;
use App\Offers\ExtraRequest;
use App\Offers\Intake\OpenDraft;
use App\Offers\Offer;
use App\Support\Surface;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Offers\Pricing;
use App\Offers\Reprice;
use App\Offers\Signing;
use App\Offers\SupplierRequest;
use App\Park\Actions\MarkDoc;
use App\Park\Actions\PurgeLetters;
use App\Park\City;
use App\Park\DocKind;
use App\Park\DocState;
use App\Park\Documents\ActPdf;
use App\Park\EventType;
use App\Park\InspectionKind;
use App\Park\PhotoStage;
use App\Park\RequestType;
use App\Park\Sale;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Support\Ago;
use App\Support\Detail;
use App\Support\Docs;
use App\Support\Facets\Common;
use App\Support\Facets\Facet;
use App\Support\Facets\Facets;
use App\Support\Facets\Option;
use App\Support\ListPrefs;
use App\Support\Money;
use App\Support\Nav;
use App\Support\OfficePreview;
use App\Support\Sort;
use App\Users\User;
use App\Vendors\Actions\BindSender;
use App\Vendors\Contact;
use App\Vendors\ContactRole;
use App\Vendors\Kind;
use App\Vendors\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Почта — рабочий список дел: Требуют внимания · Все · Прочее · Отправленные · Архив (ветка там, где её последнее
 * письмо, как в Gmail). Дело — ТС (в CRM предложение), цепочка «Из писем» или письмо без машины; пилюля выбирает
 * дела, а в секции стоят все письма дела. Тем же экраном стоит «Из писем» (`fromMail`) — с вшитой выборкой
 * «надо завести» и без пилюль. Правила выборок — `Mail\Boxes`, их же читает бейдж раздела.
 */
class MailController
{
    public const GROUPS_PER_PAGE = 30;

    /** Переключатели почты, что действуют на этот раз и не запоминаются (`ListPrefs`). */
    private const ONCE = ['unread', 'quiet'];

    /** `$queue` — адрес «Из писем»: оттуда же идут «Завести» и «Не заявка» у цепочки. */
    public function __construct(
        private Scope $scope = Scope::Offers,
        private string $base = '/work/mail',
        private string $queue = '/offers/from-mail',
    ) {}

    public function index(Request $request)
    {
        return $this->screen($request);
    }

    /**
     * «Из писем» — та же почта, но выборка вшита: только дела, которые надо завести. Пилюль на экране нет и
     * снять выборку нечем — раздел про одно дело, а не про переключение коробок.
     */
    public function fromMail(Request $request)
    {
        return $this->screen($request, 'register');
    }

    private function screen(Request $request, ?string $forced = null)
    {
        $accounts = Account::where('scope', $this->scope)->orderBy('title')->get();
        // Открываются «Цепочки» (22.11.2026, владелец: «по умолчанию должны быть цепочки»); `?box=attention` в CRM, где этой
        // пилюли больше нет, и прежний `?box=all` — тоже они.
        $box = $forced ?? (array_key_exists($request->query('box', ''), Boxes::boxes($this->scope)) ? $request->query('box') : 'chains');
        // Везде по умолчанию — свежие сверху (04.10.2026, владелец: «странная, логики нет»); «дольше ждут» — по возрастанию.
        $sort = Sort::from($request->query('sort') === 'waiting' ? 'fresh' : $request->query('sort'), Boxes::SORTS, '-fresh');
        $q = trim((string) $request->query('q'));
        // Память — своя у почты CRM и парковки и у «Из писем».
        $list = ($this->scope === Scope::Park ? 'park' : 'offers').($forced ? '-candidates' : '-mail');
        $facets = Facets::for($request, $list,
            Common::vendor('mail_threads.vendor_id'),
            Facet::column('intent', 'Смысл', ['смысл', 'смысла', 'смыслов'], Boxes::LAST_INTENT)->labels(fn (array $keys) => collect(Intent::cases())
                ->filter(fn (Intent $i) => $i->short() && in_array($i->value, $keys, true))
                ->mapWithKeys(fn (Intent $i) => [$i->value => new Option($i->value, $i->title())])->all()),
            Facet::toggle('unread', 'Непрочитанные', fn ($t) => $t->where('unread_count', '>', 0)),
            // «Прочее» (автоответы, бухгалтерия, письма без машины не от вендора) стоит во «Все»: убрать — этим чипом.
            Facet::toggle('quiet', 'Без прочего', fn ($t) => $t->where(fn ($w) => Boxes::notNoise($w))),
        )->countBy('count(distinct '.Boxes::group($this->scope).')');
        // Помнятся вендор, смысл и ящик. Переключатели — на этот раз: забытый «Непрочитанные» прятал у модератора все
        // прочитанные письма неделями (16.10.2026).
        ListPrefs::sync($request, $list, keep: [...array_diff($facets->keys(), self::ONCE), 'account'], view: false);
        $park = $this->scope === Scope::Park;
        // Ящик (13.10.2026, владелец: «письма только с offer и только с deal»): выбран — и дела, и письма в их карточках
        // только этого ящика. У парковки ящик один, в «Из писем» выборка вшита — переключателя нет.
        // Без выбора — deal@ (22.11.2026, владелец: «по умолчанию deal@xcar.ru, а не „Все ящики“»); «Все ящики» — выбор (`account=`), он помнится.
        $mailbox = ! $park && ! $forced && $accounts->count() > 1
            ? $accounts->firstWhere('slug', $request->query->has('account') ? (string) $request->query('account') : 'deal') : null;
        $boxAccounts = $mailbox ? collect([$mailbox]) : $accounts;
        $with = ['account', 'vendor', 'offer', 'offer.vendor', 'vehicle', 'vehicle.yard', 'vehicle.vendor', 'candidate.vendor', 'latestMessage.author', 'latestIncoming'];

        $all = Thread::query()->whereIn('account_id', $boxAccounts->pluck('id'))->where('messages_count', '>', 0);
        // «Входящие» и «Отправленные» — письма строками, письмо справа; лупа ищет делами, как во «Все».
        if (! $forced && in_array($box, Boxes::LETTERS, true) && $q === '') {
            return $this->letters($request, $box, $list, $boxAccounts, $all, ['sort' => $sort, 'accounts' => $accounts, 'mailbox' => $mailbox, 'park' => $park]);
        }
        // Ветки пилюли: по ним выбираются дела. Письма дела потом берутся все — действие требуется от дела,
        // а не от одного письма, поэтому секция не должна разваливаться под фильтром.
        $pick = fn (string $box) => match ($box) {
            'sent' => (clone $all)->whereNull('archived_at')->whereRaw(Boxes::LAST_DIRECTION." = 'out'"),
            'archive' => (clone $all)->whereNotNull('archived_at'),
            'attention' => (clone $all)->whereNull('archived_at')->where(fn ($w) => Boxes::attention($w, $this->scope)),
            'register' => (clone $all)->whereNull('archived_at')->where(fn ($w) => Boxes::register($w, $this->scope)),
            default => (clone $all)->whereNull('archived_at'),
        };
        // Поиск — не фильтр и идёт мимо пилюли и чипов, по всем письмам (архив тоже); «Из писем» — внутри своей
        // вшитой выборки: экран про одно дело. Адрес он не меняет.
        $threads = $q !== '' && ! $forced ? (clone $all) : $pick($box);
        if ($q !== '') {
            $threads->search($q);
        }
        $facets->apply($threads);
        $order = fn ($t) => $t->orderBy('last_message_at', $sort->dir());

        // Дело — машина, цепочка «Из писем» или предложение. Письмо, которому парсер не нашёл машину, — дело само
        // по себе: заголовка у такой секции нет (нечего писать), вендор стоит в строке.
        $group = Boxes::group($this->scope);
        // Порядок дел — по последнему письму всего дела, а не только веток, подошедших под пилюлю: карточка показывает
        // все письма дела, и время в ней должно совпадать с местом в списке.
        $keys = (clone $threads)->selectRaw("{$group} as g")->groupByRaw($group)->pluck('g');
        $at = $keys->isEmpty() ? collect() : (clone $all)->selectRaw("{$group} as g, max(last_message_at) as at")->groupByRaw($group)->pluck('at', 'g');
        $keys = $keys->sortBy(fn ($g) => (string) ($at[$g] ?? ''), SORT_STRING, $sort->desc)->values();
        $page = max(1, (int) $request->query('page', 1));
        $slice = $keys->forPage($page, self::GROUPS_PER_PAGE)->values();
        $paginator = new LengthAwarePaginator($slice, $keys->count(), self::GROUPS_PER_PAGE, $page, ['path' => $request->url(), 'query' => $request->query()]);
        // Письма дела — все, что лежат в том же ящике: пилюля выбрала дела, а не письма, и секция не разваливается.
        $rowsOf = match (true) {
            $q !== '' && ! $forced => clone $all,
            $box === 'archive' => (clone $all)->whereNotNull('archived_at'),
            default => (clone $all)->whereNull('archived_at'),
        };
        $rows = $slice->isEmpty() ? collect() : $order($rowsOf->with($with)
            ->whereRaw("{$group} in (".implode(',', array_fill(0, $slice->count(), '?')).')', $slice->all()))->get();
        $byGroup = $rows->groupBy(fn (Thread $t) => $park
            ? ($t->vehicle_id ? 'v:'.$t->vehicle_id : ($t->candidate_id ? 'c:'.$t->candidate_id : 't:'.$t->id))
            : ($t->offer_id ? 'o:'.$t->offer_id : ($t->candidate_id ? 'c:'.$t->candidate_id : 't:'.$t->id)));
        $sections = $slice->map(function (string $g) use ($byGroup) {
            $list = $byGroup->get($g, collect());
            $first = $list->first();

            [$kind, $id] = explode(':', $g, 2);
            // Дело надо завести — то же правило, что у выборки (`Boxes::register`). Признак отдельный от тона:
            // письмо-заявка бывает и с вопросом, и тогда полоска оранжевая, а кнопка «Завести» всё равно нужна.
            $register = $kind === 'c'
                ? $first?->candidate?->state === CandidateState::New
                : $kind === 't' && $first?->vendor_id && $first?->last_intent === Intent::Intake->value;

            return ['key' => $g, 'kind' => $kind, 'id' => $id, 'threads' => $list, 'vehicle' => $kind === 'v' ? $first?->vehicle : null,
                'offer' => $kind === 'o' ? $first?->offer : null, 'candidate' => $kind === 'c' ? $first?->candidate : null,
                'register' => $register,
                // Чего ждёт дело: по нему рисуется полоска слева.
                'attention' => $list->contains(fn (Thread $t) => $t->needs_reply_at !== null) ? 'reply' : ($register ? 'register' : null),
            ];
        })->filter(fn ($s) => $s['threads']->isNotEmpty())->values();
        // Карточка дела показывает все его письма — и веток в архиве, и тех, что не подошли под пилюлю: дело целиком.
        // Машина или предложение — все их ветки одним запросом, цепочка — её письма, письмо без них — его ветка.
        $own = $park ? 'vehicle_id' : 'offer_id';
        $owners = $sections->whereIn('kind', ['v', 'o'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $byOwner = $owners ? Thread::whereIn('account_id', $boxAccounts->pluck('id'))->whereIn($own, $owners)
            ->with([$park ? 'vehicle' : 'offer', 'messages.attachments', 'messages.author'])->get()->groupBy($own) : collect();
        (new EloquentCollection($sections->pluck('candidate')->filter()->unique('id')->values()->all()))->load(['messages.attachments', 'messages.author']);
        (new EloquentCollection($sections->where('kind', 't')->flatMap(fn ($s) => $s['threads'])->all()))->load(['messages.attachments', 'messages.author']);
        $sections = $sections->map(fn ($s) => $s + ['letters' => match (true) {
            in_array($s['kind'], ['v', 'o'], true) => $byOwner->get((int) $s['id'], collect())->flatMap->messages,
            (bool) $s['candidate'] => $mailbox ? $s['candidate']->messages->where('account_id', $mailbox->id) : $s['candidate']->messages,
            default => $s['threads']->flatMap->messages,
        }]);
        $threads = $paginator;

        // Список отдельным куском — им отвечает живой поиск, им же рисуется страница.
        $data = [
            'threads' => $threads,
            'sections' => $sections,
            'q' => $q,
            'filtered' => $facets->active(),
            'facets' => $facets,
            'base' => $this->base,
            'queue' => $this->queue,
            'park' => $park,
            'crm' => ! $park,
            'box' => $box,
            'forced' => $forced,
            // Метка ящика у письма — когда на экране письма разных ящиков.
            'mailboxMarks' => ! $mailbox && $accounts->count() > 1,
        ];
        if ($request->header('X-List')) {
            return response()->view('admin.mail.list', $data);
        }

        // Счётчики пилюль — число дел, одним правилом на все (раньше «Входящие» считали ветки с непрочитанными,
        // а «Ждут ответа» — все ветки, и числа были несравнимы).
        $counts = $forced ? [] : array_filter([
            'attention' => $park ? (int) $facets->applyTo($pick('attention'))->selectRaw('count(distinct '.$group.') as n')->value('n') : 0,
            'in' => $this->inboxUnread($boxAccounts, $all),
        ]);

        return view('admin.mail.index', $data + [
            'sort' => $sort,
            'accounts' => $accounts,
            'mailbox' => $mailbox,
            'counts' => array_filter($counts),
            // ?window=id — ссылка на ветку: список с открытым окном этой ветки.
            'window' => $request->query('window') ? "{$this->base}/".(int) $request->query('window').'/window'
                // ?compose= — редактор письма (`inWindow`): только новое письмо или ответ своей почты, не любой её адрес.
                : (preg_match('#^'.preg_quote($this->base, '#').'/(new(\?|$)|\d+/reply/\d+)#', (string) $request->query('compose')) ? (string) $request->query('compose') : null),
        ]);
    }

    /**
     * «Цепочки», «Входящие» и «Отправленные» — письма строками слева, открытое письмо справа (`Detail`, ключ — id письма),
     * ↑/↓ листают (17.11.2026, владелец: «во входящих только входящие, в отправленных только отправленные, классический
     * красивый gmail»). Чипы — вендор, непрочитанные, с файлами.
     * **Пачками** (22.11.2026, владелец: «схлопывать в пачки по цепочкам, чтобы было видно только последнее из цепочки»):
     * цепочка — дело (`Boxes::group`), строка — её последнее письмо этой пилюли (в «Цепочках» — в любую сторону); чипы
     * отбирают письма до пачки. «Цепочки» без пачек — цепочка плашкой, в ней все её письма. Помнится у каждой пилюли своё
     * (`?stack=1|0`), умолчание — `Boxes::STACKED`.
     */
    private function letters(Request $request, string $box, string $list, Collection $boxAccounts, Builder $all, array $data)
    {
        $detail = Detail::of($request, fn (string $id) => $this->pane((int) $id, $boxAccounts, $all));
        if ($detail->framed()) {
            return $detail->response();
        }
        $facets = Facets::for($request, $list,
            Common::vendor('mail_threads.vendor_id'),
            // Непрочитанные — входящие, как число «Почты» (`Boxes::unreadCount`): наше письмо читать не нужно (23.11.2026).
            Facet::toggle('unread', 'Непрочитанные', fn ($m) => $m->where('mail_messages.is_seen', false)->where('mail_messages.outside', true)),
        )->countBy('count(*)');
        ListPrefs::sync($request, "{$list}-{$box}", keep: ['stack'], view: false, sort: false);
        $stack = match ((string) $request->query('stack')) {
            '1' => true, '0' => false, default => Boxes::STACKED[$box]
        };
        // «Цепочки» без пачек — плашками цепочек: страница — цепочки, а не письма.
        $plates = $box === 'chains' && ! $stack;
        $accounts = $boxAccounts->pluck('id');
        $query = Boxes::letters($accounts, $box, $all);
        $facets->apply($query);
        $group = Boxes::group($this->scope);
        $order = fn ($q) => $q->orderByDesc('mail_messages.date_at')->orderByDesc('mail_messages.id');
        // ТС парковки — тоже сразу: строка берёт марку и вендора (17.11.2026: на парковке ТС грузилось на каждую строку отдельно).
        $with = ['account', 'author', 'attachments', 'addresses', 'thread.offer.vendor', 'thread.offer.car', 'thread.vendor', 'thread.candidate.vendor',
            ...($data['park'] ? ['thread.vehicle.vendor'] : [])];
        if ($stack || $plates) {
            // Верхнее письмо каждой цепочки — тем же порядком, что список.
            $tops = (clone $query)->select(DB::raw("distinct on ({$group}) mail_messages.id"))
                ->orderByRaw("{$group}, mail_messages.date_at desc, mail_messages.id desc");
            $page = $order(Message::query()->whereIn('mail_messages.id', $tops))->with($with)->simplePaginate($plates ? 20 : 50)->withQueryString();
        } else {
            $page = $order($query->with($with))->simplePaginate(50)->withQueryString();
        }
        // Цепочка письма — ключ дела, тот же, что у «Все делами» (`Boxes::group`), в тех же ящиках.
        $key = fn (Message $m) => match (true) {
            $data['park'] && (bool) $m->thread?->vehicle_id => 'v:'.$m->thread->vehicle_id,
            ! $data['park'] && (bool) $m->thread?->offer_id => 'o:'.$m->thread->offer_id,
            (bool) $m->thread?->candidate_id => 'c:'.$m->thread->candidate_id,
            default => 't:'.$m->thread_id,
        };
        $items = collect($page->items());
        $keys = $items->map($key)->unique()->values();
        $in = fn (string $col) => "{$col} in (".implode(',', array_fill(0, max(1, $keys->count()), '?')).')';
        // Плашки — все письма цепочек страницы, свежие сверху (чипы выбрали цепочки, а не письма — как «Все делами»).
        $members = $plates && $keys->isNotEmpty()
            ? $order(Boxes::letters($accounts, 'chains', $all)->whereRaw($in($group), $keys->all())->with($with))->get()
            : $items;
        // «Ч.2» того же письма (те же слова от того же адреса в сутки) — одной строкой с первой частью, файлы суммой.
        $continued = [];
        if (! $stack) {
            foreach ($members->groupBy('thread_id') as $letters) {
                $continued += NodeTitle::continued($letters->sortBy(fn (Message $m) => $m->date_at?->getTimestamp() ?? 0)->values());
            }
        }
        $files = [];
        foreach ($members as $m) {
            $head = $continued[$m->id] ?? $m->id;
            $files[$head] = ($files[$head] ?? 0) + $m->files()->count();
        }
        $members = $members->reject(fn (Message $m) => isset($continued[$m->id]))->values();
        // По цепочке одним запросом: сколько писем во всей переписке по машине (кружок в строке, 17.11.2026, владелец: «вся
        // переписка по машине»), сколько входящих и наших в живых ветках (листы под пачкой), последнее непрочитанное входящее
        // (пачка жирная и раскрывает его справа — показ делает прочитанным) и последнее входящее (собеседник цепочки).
        $latest = fn (string $where) => "(array_agg(mail_messages.id order by mail_messages.date_at desc, mail_messages.id desc) filter (where {$where}))[1]";
        $live = 'mail_threads.archived_at is null';
        $stats = $keys->isEmpty() ? collect() : Message::query()->join('mail_threads', 'mail_threads.id', '=', 'mail_messages.thread_id')
            ->whereIn('mail_messages.account_id', $accounts)->whereIn('mail_messages.thread_id', (clone $all)->select('mail_threads.id'))
            ->whereRaw($in($group), $keys->all())
            ->selectRaw("{$group} as g, count(*) as n, count(*) filter (where {$live} and mail_messages.outside) as n_in,"
                ." count(*) filter (where {$live} and not mail_messages.outside) as n_out,"
                .' '.$latest("{$live} and mail_messages.outside and not mail_messages.is_seen").' as unread_id,'
                .' '.$latest("{$live} and mail_messages.outside").' as peer_id')
            ->groupByRaw($group)->toBase()->get()->keyBy('g');
        // Собеседник пачки «Цепочек», где последнее письмо наше, — отправитель последнего входящего: кружок и имя как в мессенджере.
        $peerIds = $box === 'chains' && $stack
            ? $items->reject(fn (Message $m) => $m->outside)->map(fn (Message $m) => $stats[$key($m)]->peer_id ?? null)->filter()->values()->all() : [];
        $peers = $peerIds ? Message::with(['account', 'author', 'addresses', 'thread'])->findMany($peerIds)->keyBy('id') : collect();
        $rows = $members->map(function (Message $m) use ($key, $stats, $stack, $box, $peers, $files) {
            $s = $stats[$key($m)] ?? null;
            $mine = match ($box) {
                'in' => $s?->n_in, 'sent' => $s?->n_out, default => ($s?->n_in ?? 0) + ($s?->n_out ?? 0)
            };
            $hidden = $stack ? (int) $mine - 1 : 0;
            $unread = $stack && $box !== 'sent' && $s?->unread_id ? (int) $s->unread_id : null;

            return (object) ['message' => $m, 'chain' => $key($m), 'files' => $files[$m->id] ?? 0, 'size' => (int) ($s->n ?? 1),
                'sheets' => min(2, max(0, $hidden)), 'unread' => $unread, 'open' => $unread ?? $m->id,
                'peer' => $box === 'chains' && $stack && ! $m->outside && $s?->peer_id ? $peers->get((int) $s->peer_id) : null];
        });
        $view = ['rows' => $rows, 'page' => $page, 'box' => $box, 'base' => $this->base, 'park' => $data['park'],
            'filtered' => $facets->active(), 'queue' => $this->queue, 'stack' => $stack, 'plates' => $plates];
        if ($request->header('X-List')) {
            return response()->view('admin.mail.letters', $view + ['more' => true]);
        }

        return view('admin.mail.index', $view + $data + [
            'detail' => $detail,
            'letters' => true,
            'threads' => $page,
            'q' => '',
            'facets' => $facets,
            'crm' => ! $data['park'],
            'forced' => null,
            'counts' => array_filter(['in' => $this->inboxUnread($boxAccounts, $all), 'attention' => $data['park'] ? Boxes::attentionCount(Scope::Park) : 0]),
            'window' => null,
        ]);
    }

    /** Непрочитанные «Входящие» ящиков экрана — число пилюли. */
    private function inboxUnread(Collection $boxAccounts, Builder $all): int
    {
        return (int) Boxes::letters($boxAccounts->pluck('id'), 'in', $all)->where('mail_messages.is_seen', false)->count();
    }

    /**
     * Письмо справа: ветка этого письма, оно раскрыто, остальные свёрнуты. Чужое письмо (другой ящик, ветка не видна,
     * город) — пусто. Прочитанным его делает показ (`mail_seen_controller` → `seen`), не этот ответ: его же берёт
     * предзагрузка соседних строк.
     */
    private function pane(int $id, Collection $boxAccounts, Builder $all)
    {
        // Ветка в архиве — письма нет: «В архив» справа уводит к следующему (`detail-gone`).
        $message = Message::whereIn('account_id', $boxAccounts->pluck('id'))->whereIn('thread_id', (clone $all)->whereNull('archived_at')->select('mail_threads.id'))->find($id);
        if (! $message) {
            return null;
        }
        $thread = $message->thread->load(['account', 'offer.vendor', 'offer.car', 'vehicle.vendor', 'candidate.vendor', 'vendor',
            'messages.account', 'messages.thread', 'messages.attachments', 'messages.addresses', 'messages.author']);
        $park = $this->scope === Scope::Park;
        // Под письмом — вся переписка по машине (17.11.2026, владелец), как в окне писем дела: предложение и ТС — их ветки
        // (`Thread::ofCar`), цепочка «Из писем» — её письма, иначе ветка.
        $with = ['messages.account', 'messages.thread', 'messages.attachments', 'messages.addresses', 'messages.author'];
        $car = $park ? $thread->vehicle : $thread->offer;
        $case = match (true) {
            (bool) $car => Thread::ofCar($car, auth()->user())->with($with)->get()->flatMap->messages,
            (bool) $thread->candidate => $thread->candidate->load(['messages.account', 'messages.thread', 'messages.attachments', 'messages.addresses', 'messages.author'])->messages,
            default => $thread->messages,
        };

        return view('admin.mail.pane', ['message' => $thread->messages->firstWhere('id', $message->id) ?? $message, 'thread' => $thread, 'base' => $this->base,
            'park' => $park, 'case' => $case->unique('id')->values()]);
    }

    /**
     * Прочитано / не прочитано — одно письмо, открытое справа (`read=0` — «Не прочитано» в «⋯»). Прочитанным становится и всё,
     * что пришло в ветку раньше: открыли письмо — видели и переписку над ним (как в Gmail).
     */
    public function seen(Request $request, Message $message, MarkMessageRead $mark)
    {
        $this->guardMessage($message);
        $read = $request->boolean('read', true);
        $ids = $mark($message, $read);
        // Показ письма справа (`mail_seen_controller`) — без перехода: строки, которым сменили флаг, и число «Входящих».
        if ($request->expectsJson()) {
            return response()->json(['ids' => $ids, 'read' => $read, 'unread' => Boxes::unreadCount($this->scope)]);
        }

        // «Не прочитано» справа — письмо закрывается (иначе показ снова сделал бы его прочитанным), список остаётся.
        $back = url()->previous();
        parse_str((string) parse_url($back, PHP_URL_QUERY), $query);
        unset($query['peek']);

        return redirect(strtok($back, '?').($query ? '?'.http_build_query($query) : ''))->with('toast', $read ? 'Прочитано' : 'Не прочитано');
    }

    /** «Заявка ›» (CRM: «Предложение ›») у нераспознанного письма: кандидат из ветки как есть, дальше обычная дверь заведения. */
    public function candidate(Request $request, Thread $thread)
    {
        $this->guard($thread);
        $message = $thread->messages()->with(['thread', 'account'])->where('direction', Direction::In)->orderByDesc('date_at')->first()
            ?? $thread->messages()->with(['thread', 'account'])->orderByDesc('date_at')->first();
        abort_if(! $message, 404);
        $candidate = $thread->candidate ?? app(ChainBuilder::class)->attach($message, force: true, quiet: true);
        abort_if(! $candidate, 404);
        if ($this->scope === Scope::Park) {
            return redirect('/requests/new?candidate='.$candidate->id);
        }
        if ($candidate->state === CandidateState::Promoted && $candidate->offer) {
            return $this->toOffer($candidate->offer);
        }

        return $this->draft($candidate, $request->user());
    }

    /**
     * К заведённому предложению: в CRM — его страница; в XCAR Mail (20.11.2026) страниц CRM нет — назад к письму, и оно открывается
     * шторкой сделки поверх почты (`x-ui.deal-sheet`, flash `deal-sheet`).
     */
    private function toOffer(Offer $offer, ?string $toast = null)
    {
        $to = Surface::current() === Surface::Mail
            ? redirect()->back(fallback: $this->base)->with('deal-sheet', $offer->crmUrl())
            : redirect("/offers/{$offer->number}");

        return $toast ? $to->with('toast', $toast) : $to;
    }

    /**
     * В архив ↔ вернуть одну ветку. Это «В архив» из окна письма: окно про ту ветку, которую открыли, и это
     * единственный способ убрать из дела автоответ или бухгалтерию. Свайп в списке архивирует дело целиком.
     */
    public function archive(Request $request, Thread $thread, ArchiveThread $archive)
    {
        $this->guard($thread);
        $thread->archived_at ? $archive->restoreWithCandidate($thread) : $archive($thread);
        if ($request->header('Turbo-Frame') === 'letters-frame') {
            return redirect("{$this->base}/{$thread->id}/window");
        }
        // Письмо справа во «Входящих»: строка уходит, справа — следующее (`Detail`).
        if ($request->header('Turbo-Frame') === Detail::FRAME) {
            return back()->with('detail-gone', true)->with('detail-advance', true)->with('toast', $thread->archived_at ? 'В архиве' : 'Снова во входящих');
        }

        return back()->with('toast', $thread->archived_at ? 'В архиве' : 'Снова во входящих')->with('toast-undo-url', $thread->archived_at ? request()->url() : null);
    }

    /**
     * Свайп дела: в архив уходят все его ветки, а не одно письмо — архивировать одно письмо из цепочки смысла
     * нет. Цепочка «Из писем» едет вместе с письмами: в архив — «не заявка», обратно — снова ждёт.
     */
    public function archiveCase(Request $request, string $kind, int $id, ArchiveThread $archive, FreezeMessages $freeze, ChainBuilder $chains)
    {
        $restore = $request->boolean('restore');
        // «Вернуть» в тосте — тот же адрес обратно.
        $undo = "{$this->base}/case/{$kind}/{$id}/archive?restore=1";
        // Состояние двигается только у живой цепочки: «закрыта» — конечное, а заведённую свайп по письмам
        // отклонять не должен. У таких дел свайп остаётся просто архивом писем.
        $candidate = $kind === 'c' ? Candidate::where('scope', $this->scope)->findOrFail($id) : null;
        if ($candidate && in_array($candidate->state, [CandidateState::New, CandidateState::Rejected], true)) {
            $this->toggleChain($candidate, $restore, $archive, $freeze, $chains);
        } else {
            $column = match ($kind) {
                'v' => 'vehicle_id', 'o' => 'offer_id', 'c' => 'candidate_id', default => 'id'
            };
            $threads = Thread::whereIn('account_id', Account::where('scope', $this->scope)->select('id'))->where($column, $id)->get();
            abort_if($threads->isEmpty(), 404);
            foreach ($threads as $thread) {
                $restore ? $archive->restoreWithCandidate($thread) : $archive($thread);
            }
        }
        if (str_contains((string) $request->header('Accept'), 'turbo-stream')) {
            return response()->view('admin.mail.case-stream', ['kind' => $kind, 'id' => $id, 'restore' => $restore, 'undo' => $undo])->header('Content-Type', 'text/vnd.turbo-stream.html');
        }

        return back()->with('toast', $restore ? 'Снова во входящих' : 'В архиве')->with('toast-undo-url', $restore ? null : $undo);
    }

    /**
     * «Завести» у цепочки в CRM: черновик заводится сразу (`PromoteCandidate` — поля из писем, документов во вложениях
     * и VIN) и открывается обычный редактор. До первого «Сохранить» он помнит, что пришёл из писем (сессия
     * `mail-draft.{id}`): внизу «Отменить» и «Не заявка» вместо «Выставить в продажу». Предложение с тем же убытком или VIN
     * уже есть — письма к нему, второго черновика нет.
     */
    public function promote(Request $request, Candidate $candidate)
    {
        abort_if($candidate->state === CandidateState::Promoted || $candidate->scope !== $this->scope || $this->scope !== Scope::Offers, 404);

        return $this->draft($candidate, $request->user());
    }

    private function draft(Candidate $candidate, User $by)
    {
        [$offer, $outcome] = app(PromoteCandidate::class)->promote($candidate, $by);
        if ($outcome !== OpenDraft::CREATED) {
            return $this->toOffer($offer, 'Письма привязаны к предложению');
        }
        session()->put("mail-draft.{$offer->id}", $candidate->id);

        return $this->toOffer($offer);
    }

    /**
     * «Отменить» и «Не заявка» у черновика из писем до первого сохранения: черновика не было — он удаляется вместе
     * с перенесёнными фото (ТС парковки той же машины остаётся, `Sale::end`), письма отвязываются и цепочка снова ждёт
     * в «Из писем»; «Не заявка» ещё и уводит её в архив.
     */
    public function dropDraft(Request $request, Offer $offer, ArchiveThread $archive, FreezeMessages $freeze, ChainBuilder $chains)
    {
        $candidate = Candidate::where('scope', Scope::Offers)->where('offer_id', $offer->id)->where('state', CandidateState::Promoted)->first();
        abort_if($this->scope !== Scope::Offers || ! $candidate || $offer->state !== OfferState::Draft || $offer->published_at !== null, 404);
        DB::transaction(function () use ($offer, $candidate) {
            Thread::where('offer_id', $offer->id)->update(['offer_id' => null]);
            $candidate->update(['state' => CandidateState::New, 'offer_id' => null]);
            $offer->delete();
        });
        // Цепочка снова ждёт человека — сама черновиком больше не заводится (`AutoOffer`).
        AutoOffer::declined($candidate->id);
        session()->forget("mail-draft.{$offer->id}");
        Nav::forgetStaffCounts();
        if ($request->boolean('decline')) {
            $this->toggleChain($candidate->refresh(), false, $archive, $freeze, $chains);

            return redirect($this->queue)->with('toast', 'В архиве');
        }

        return redirect($this->queue);
    }

    /** Окно писем предложения (как `/cars/{v}/letters` у ТС): все ветки одной лентой, этапы — из его цепочки, «Ответить» внизу. */
    public function offerLetters(Request $request, Offer $offer, MarkThreadRead $markRead)
    {
        abort_if($this->scope !== Scope::Offers, 404);
        $threads = Thread::ofCar($offer, $request->user())->with(['messages.account', 'messages.thread', 'messages.attachments', 'messages.addresses', 'messages.author'])->get();
        // Открыли со строки списка (?at=) — письма прочитаны, как у окна ветки.
        if ($request->query('at') && ! str_contains($request->header('Sec-Purpose', $request->header('X-Sec-Purpose', '')), 'prefetch')) {
            $threads->each(fn (Thread $t) => $markRead($t));
        }

        return view('admin.offers.letters', [
            'offer' => $offer,
            'messages' => $threads->flatMap->messages,
            'candidate' => Candidate::where('offer_id', $offer->id)->latest('id')->first(),
            'base' => $this->base,
        ]);
    }

    /** «Не заявка» ↔ «Снова ждёт»: решение человека, свёртка его не трогает. */
    public function decline(Candidate $candidate, ArchiveThread $archive, FreezeMessages $freeze, ChainBuilder $chains)
    {
        abort_if($candidate->scope !== $this->scope || ! in_array($candidate->state, [CandidateState::New, CandidateState::Rejected], true), 404);
        $rejected = $this->toggleChain($candidate, $candidate->state === CandidateState::Rejected, $archive, $freeze, $chains);

        // «Не заявка» по ошибке — «Вернуть» в тосте: тот же адрес переключает обратно.
        return back()->with('toast', $rejected ? 'В архиве' : 'Снова ждёт')->with('toast-undo-url', $rejected ? request()->url() : null);
    }

    /** Цепочка и её письма ходят в архив вместе; вернулась — письма читаются заново (свёртка размораживает). */
    private function toggleChain(Candidate $candidate, bool $restore, ArchiveThread $archive, FreezeMessages $freeze, ChainBuilder $chains): bool
    {
        $candidate->update(['state' => $restore ? CandidateState::New : CandidateState::Rejected]);
        $archive->candidate($candidate, ! $restore);
        if ($restore) {
            $chains->fold($candidate);
            if ($last = $candidate->messages()->where('mail_messages.direction', Direction::In)->orderByDesc('mail_messages.date_at')->first()) {
                ImportCandidateFiles::dispatch($candidate->id, $last->id);
            }
        } else {
            // В архиве распарсенное и файлы не хранятся: «Вернуть» прочитает письма заново.
            $freeze->freeze($candidate->messages()->pluck('mail_messages.id')->all());
        }
        Nav::forgetStaffCounts();

        return ! $restore;
    }

    /** Окно ветки (фрейм letters-frame в x-mail.window): все письма целиком, «Ответить» под каждым, привязка. */
    public function window(Request $request, Thread $thread, MarkThreadRead $markRead)
    {
        $this->guard($thread);
        $thread->load(['account', 'offer', 'vehicle', 'candidate', 'messages.account', 'messages.thread', 'messages.attachments', 'messages.addresses', 'messages.author']);
        if (! str_contains($request->header('Sec-Purpose', $request->header('X-Sec-Purpose', '')), 'prefetch')) {
            $markRead($thread);
        }

        return view('admin.mail.window', ['thread' => $thread, 'base' => $this->base]);
    }

    /** Окно цепочки «Из писем»: письма всех её веток одной лентой; ?at= — открыть на этом письме (строка списка). */
    public function candidateLetters(Request $request, Candidate $candidate, MarkThreadRead $markRead)
    {
        abort_unless($candidate->scope === $this->scope, 404);
        $candidate->load(['vendor', 'messages.account', 'messages.thread', 'messages.attachments', 'messages.addresses', 'messages.author']);
        if (! str_contains($request->header('Sec-Purpose', $request->header('X-Sec-Purpose', '')), 'prefetch')) {
            foreach ($candidate->threads() as $thread) {
                $markRead($thread);
            }
        }

        return view('admin.mail.candidate-window', [
            'candidate' => $candidate,
            'base' => $this->base,
            'queue' => $this->queue,
            'park' => $this->scope === Scope::Park,
            'at' => (int) $request->query('at') ?: true,
        ]);
    }

    public function show(Request $request, Thread $thread, MarkThreadRead $markRead)
    {
        $this->guard($thread);
        $thread->load(['account', 'offer', 'vehicle', 'candidate', 'messages.account', 'messages.thread', 'messages.attachments', 'messages.addresses', 'messages.author']);
        // Касание строки — ещё не чтение: префетч Turbo идёт с Sec-Purpose: prefetch.
        if (! str_contains($request->header('Sec-Purpose', $request->header('X-Sec-Purpose', '')), 'prefetch')) {
            $markRead($thread);
        }

        return view('admin.mail.thread', [
            'thread' => $thread,
            'base' => $this->base,
        ]);
    }

    public function compose(Request $request, Composer $composer, StoreOutboxFile $outbox, ActPdf $act, StorageActPdf $actPdf)
    {
        // Письмо пишется только в окне (28.10.2026, владелец: «зачем отдельной страницей, мы же делали в окне») — прямой заход
        // по адресу (старая ссылка, Telegram) открывает почту с этим окном. Первым делом: без ящика окно скажет само.
        if (! $request->header('Turbo-Frame')) {
            return $this->inWindow($request);
        }
        // С каких ящиков пишет человек — `Senders` (offer@ только принимает: с него не начинают и не отвечают).
        $accounts = Senders::for($request->user());
        $account = $accounts->firstWhere('slug', $request->query('account')) ?? Senders::pick($request->user(), null, $this->scope);
        abort_unless($account, 404);
        $template = $request->query('template') ? Template::where('scope', $this->scope)->letters()->find($request->query('template')) : null;
        // Предложение — письмо CRM, машина и счёт — письма парковки: чужая сторона их не подставляет.
        $sale = $this->scope === Scope::Offers;
        $offer = $sale && $request->query('offer') ? OfferNumber::find((string) $request->query('offer')) : null;
        $vehicle = ! $sale && $request->query('car') ? Vehicle::find($request->query('car')) : null;
        // Счёт за хранение вендору: ТС счёта, шаблон «Счёт за хранение», PDF счёта и акта, адресат — бухгалтерия вендора.
        // Счёт в письме — деньги: приёмщику парковки (`User::parkMoney`) не подставляется (27.10.2026).
        abort_if(! $sale && $request->query('invoice') && ! $request->user()->parkMoney(), 404);
        // Что отправить с документом месяца — ответ на вопрос «Отправить» (`park.money.closing-actions`): счёт и акт, только
        // акт, только счёт; от него вложения и шаблон письма (`Template::SENDS`).
        $send = array_key_exists((string) $request->query('send'), Template::SENDS) ? (string) $request->query('send') : 'both';
        // На сверку — документ не фиксируется (номер и суммы живые до финальной отправки), письмо — шаблоном сверки.
        $review = $request->boolean('review');
        $kind = ($review ? '-review' : '').Template::SENDS[$send];
        $invoice = ! $sale && $request->query('invoice') ? Invoice::with(['vehicle', 'party'])->find($request->query('invoice')) : null;
        if ($invoice?->vehicle) {
            $vehicle = $invoice->vehicle;
            $template ??= Template::park('invoice'.$kind);
        }
        // Живой документ месяца (`?closing=2026-09|p71:park`, исправленный — `&replaces=`): письмо с его счётом и актом,
        // номер — какой он получит; фиксируется отправкой (`send` → `FixClosingDocument`), закрыли не отправив — остался живым.
        $closing = ! $sale && $request->query('closing') ? self::closingKey((string) $request->query('closing'), $request->query('replaces')) : null;
        $draft = $closing ? Closing::draft(...$closing) : null;
        abort_if($closing && ! $draft, 409, 'Документ уже отправлен');
        // Живой счёт покупателю на ТС — письмом той ТС, как выставленный (шаблон «Счёт за хранение», ветка ТС).
        if ($draft && ! $draft->isRegistry()) {
            $vehicle = $draft->vehicle;
            $template ??= Template::park('invoice'.$kind);
        }
        // Счёт-реестр за месяц — новое письмо в бухгалтерию плательщика (вендора, иначе адрес контрагента), без ветки ТС.
        $registryValues = [];
        if (($doc = $draft ?? $invoice)?->isRegistry()) {
            $template ??= Template::park('registry'.$kind);
            $payer = Vendor::with('contacts')->where('party_id', $doc->party_id)->first();
            // Продавец в теме сверки (09.11.2026, владелец: «Стоянка ИП Кузнецов А.В. отчет-акт за октябрь 2026 на сверку»):
            // ИП — «ИП Кузнецов А.В.», организация — названием.
            $seller = $doc->seller->party();
            $registryValues = ['seller' => $seller->kind === PartyKind::Entrepreneur ? 'ИП '.str_replace('. ', '.', (string) $seller->director) : $seller->name,
                'month' => mb_strtolower(($doc->month ?? now())->translatedFormat('F Y')), 'count' => (string) $doc->registryVehicles()->count(),
                'invoice' => $doc->label(), 'sum' => Money::rub($doc->total),
                'to' => $payer?->email(ContactRole::Accounting, ContactRole::Storage, ContactRole::Claims) ?? $doc->party->email ?? ''];
        }
        $thread = null;
        $parent = null;
        $values = [];
        if ($offer) {
            // Адресат — ответственный по убытку, иначе контакт вендора по реализации (контакты CRM); его копии — в cc.
            $offer->loadMissing('vendor.contacts');
            $values = self::placeholders($offer) + [
                'to' => $offer->contact_email ?? $offer->vendor?->saleAddressee() ?? '',
                'cc' => implode(', ', $offer->vendor?->ccEmails(sale: true) ?? []),
            ];
            if (! $request->query('account') && $offer->vendor?->mail_account_id) {
                $account = $accounts->firstWhere('id', $offer->vendor->mailAccount?->sender()->id ?? $offer->vendor->mail_account_id) ?? $account;
            }
            // Письмо вендору — ответом на его последнее письмо (а без него — на то, которым пришло предложение): у
            // страховой оно встаёт в ту же переписку, а ответ приходит на deal@ (05.10.2026).
            $parent = self::vendorLetter($offer);
            if ($parent && ! $request->query('account') && ! $offer->vendor?->mail_account_id) {
                $account = $accounts->firstWhere('id', $parent->account->sender()->id) ?? $account;
            }
            // Контакт «Реализация» у вендора (Альфа — implementation@, 08.10.2026) — адресат письма по продаже, кто бы ни
            // написал последнее письмо: ответственный по убытку и пересылка Андрея — не те, кто согласует реализацию.
            $saleTo = $offer->vendor?->saleTo();
        }
        // Доп. запрос поставщику (17.11.2026, владелец по CTTM Cargoline: «Сделать доп. запрос» над письмами машины): галки —
        // ссылками над текстом (`pick[]`, город — `city`), письмо собирает `ExtraRequest` и уходит ответом в ветку
        // поставщика, как письмо этапа.
        $extra = $offer && $request->boolean('extra') ? ExtraRequest::picked((array) $request->query('pick', [])) : null;
        $extraCity = null;
        if ($extra !== null) {
            $extraCity = trim((string) $request->query('city', '')) ?: ExtraRequest::cityOf($offer, $extra);
            $template = new Template(['subject' => '{{ claim_ref }}', 'body' => ExtraRequest::text($offer, $extra, $extraCity)]);
        }
        if ($vehicle) {
            // Письмо о машине отвечает в ту ветку, которой приехала заявка.
            // Ветка письма покупателю (пропуск по QR) — не переписка с вендором.
            $thread = Thread::where('vehicle_id', $vehicle->id)->park()->where('buyer', false)->orderByDesc('last_message_at')->first();
            $parent = $thread?->messages()->where('direction', Direction::In)->orderByDesc('date_at')->first();
            $vehicle->loadMissing('vendor.contacts');
            $values = self::vehiclePlaceholders($vehicle) + ['to' => $invoice || $draft
                ? ($vehicle->vendor?->email(ContactRole::Accounting, ContactRole::Storage, ContactRole::Claims) ?? $parent?->replyToAddress() ?? '')
                : ($parent?->replyToAddress() ?? $vehicle->vendor?->email(ContactRole::Storage, ContactRole::Claims) ?? '')];
        }
        // Письмо о выдаче — ответом в ветку заявки, как их писали руками (06.11.2026, владелец и Сергей: «Re: Направление на
        // хранение», в копии все, кому был адресован изначальный запрос): тема «Re: …» и цитата ветки, адресат — кто писал
        // последним, копия — все адресаты первого письма ветки и последнего (кроме наших ящиков и самого адресата). Из дела
        // само не подтягивается ничего (владелец: «фото к письму не нужны»), кроме файлов, приложенных в окне выдачи (ниже).
        $release = $vehicle && ! $invoice && ! $draft && $request->query('act') === 'release';
        // Отвечаем не на последнее письмо ветки (там бывают внутренние пересылки), а на письмо о реализации ТС, без него — на
        // заявку о приёме (владелец 06.11.2026); нет ни того, ни другого — как было, на последнее письмо вендора.
        if ($release && ($asked = self::releaseParent($vehicle))) {
            $parent = $asked;
            $thread = $asked->thread;
            // Пересылка сотрудника — отвечаем вендору из цитаты, как обычный ответ (`Composer::reply`).
            $values['to'] = $composer->reply($asked)['to'] ?: ($asked->replyToAddress() ?? $values['to']);
        }
        if ($release && $parent) {
            $values['cc'] = implode(', ', self::requestCopies($parent, $vehicle, (string) $values['to']));
            if (! $request->query('account')) {
                $account = $accounts->firstWhere('id', $parent->account->sender()->id) ?? $account;
            }
        }
        // Письмо о приёме у вендора с выдачей по QR — со ссылкой на анкету покупателя: в шаблоне её нет — абзацем
        // перед подписью (шаблоны в базе заведены раньше, чем появилась выдача по QR).
        if ($vehicle && $template && $vehicle->vendor?->release_by_qr && $request->query('act') === 'intake' && ! str_contains((string) $template->body, 'pickup_link')) {
            $template = (clone $template)->forceFill(['body' => self::withPickupNotice((string) $template->body)]);
        }
        // Заявка поставщику на выкуп пачкой (Каркаде, 09.10.2026): все сделки вендора, что ждут заявки, — файлом во
        // вложении; отправили — они идут дальше «Заявка отправлена» (`send`).
        $requestVendor = $sale && $request->query('request') ? Vendor::with('contacts')->find($request->query('request')) : null;
        $supplierRequest = $requestVendor ? SupplierRequest::for($requestVendor->id) : null;
        if ($supplierRequest) {
            abort_if($supplierRequest->deals->isEmpty(), 404);
            $template ??= Template::supplierRequest();
            $values = ['count' => (string) $supplierRequest->deals->count(), 'to' => $requestVendor->saleAddressee() ?? '',
                'cc' => implode(', ', $requestVendor->ccEmails(sale: true))];
            if (! $request->query('account') && $requestVendor->mail_account_id) {
                $account = $accounts->firstWhere('id', $requestVendor->mailAccount?->sender()->id ?? $requestVendor->mail_account_id) ?? $account;
            }
        }
        $values += $registryValues;
        // Переписка по документу месяца (09.11.2026): была отправка на сверку — следующее письмо (финал, ещё сверка) ответом в
        // ту же ветку, у страховой оно встаёт в ту же переписку. Ветку знает `mail_threads.closing_key`.
        $closingThread = $closing ? Thread::where('closing_key', (string) $request->query('closing'))->where('account_id', $account->id)->latest('last_message_at')->first() : null;
        if ($closingThread) {
            $thread = $closingThread;
            $parent = $closingThread->messages()->reorder()->orderByDesc('date_at')->first();
        }
        $defaults = ($offer || $release || $closingThread) && $parent ? $composer->replyWith($parent, $account, $template, $values) : $composer->fresh($account, $template, $values);
        if ($saleTo ?? null) {
            $defaults['to'] = $saleTo;
        }
        // Адрес из копии вендора, ставший адресатом (общий total@ Т-Страхования, когда писем по сделке нет), в копии не повторяем.
        $to = array_map(fn ($a) => mb_strtolower(trim($a)), explode(',', (string) ($defaults['to'] ?? '')));
        $defaults['cc'] = implode(', ', array_filter(array_map('trim', explode(',', (string) ($defaults['cc'] ?? ''))), fn ($a) => $a !== '' && ! in_array(mb_strtolower($a), $to, true)));
        if ($parent && ! $offer) {
            $defaults['subject'] = $defaults['subject'] ?: 'Re: '.$parent->subject;
        }
        // Тема письма о выдаче — с машиной (06.11.2026, Сергей): «RE: Z691/046/06548/26 ч.1 - Kia Rio 2019 VIN …»;
        // номера убытка в теме нет — и он хвостом; уже есть VIN — не повторяем.
        if ($release && ! $request->old()) {
            $subject = trim((string) ($defaults['subject'] ?? ''));
            if (! ($vehicle->vin && str_contains($subject, $vehicle->vin))) {
                $line = $vehicle->docLine(! ($vehicle->ref && str_contains($subject, $vehicle->ref)));
                $defaults['subject'] = $line === '' ? $subject : ($subject === '' ? $line : $subject.' - '.$line);
            }
        }
        if ($invoice && ! $request->old()) {
            foreach (array_intersect(['file', 'act'], $send === 'act' ? ['act'] : ($send === 'invoice' ? ['file'] : ['file', 'act'])) as $collection) {
                foreach ($invoice->getMedia($collection) as $media) {
                    $defaults['files'][] = $outbox->put($media->file_name, file_get_contents($media->getPath()));
                }
            }
            // Счёт на ТС без файла акта — PDF акта хранения на лету (у реестра файлы кладёт фиксация).
            if ($send !== 'invoice' && ! $invoice->getFirstMedia('act') && $invoice->kind === ChargeKind::Storage && ! $invoice->isRegistry()) {
                $defaults['files'][] = $outbox->put('akt-hraneniya-'.$invoice->actNo().'-'.$invoice->year.'.pdf', $actPdf->render($invoice));
            }
        } elseif ($release && ! $request->old()) {
            // Письмо о выдаче — с тем, что приложили в окне выдачи (06.11.2026, владелец): файлы шага (акт скрепкой или
            // «Документ из фото») и снятые там кадры. Остальное из дела само не подтягивается.
            $stepFiles = $vehicle->papers()->filter(fn ($m) => $m->getCustomProperty('stage') === PhotoStage::Release->value)
                ->concat($vehicle->photos()->filter(fn ($m) => PhotoStage::of($m) === PhotoStage::Release));
            foreach ($stepFiles as $media) {
                if (is_file($media->getPath())) {
                    $defaults['files'][] = $outbox->put($media->getCustomProperty('title') ?: $media->file_name, file_get_contents($media->getPath()));
                }
            }
        }

        // Письмо поставщику об оплате (шаблон этапа, Альфа): документ об оплате, что приложили на шаге оплаты, — во вложении
        // (18.10.2026: «Платёжное поручение во вложении» уходило без него) одним PDF (10.11.2026, владелец: «из двух фоток, как
        // с паспортом, формировался пдф» — `PaymentProofPdf`).
        // Документам поставщику (Совкомбанк) платёжка менеджера ни к чему: это его оплата нам, а не наша поставщику.
        if ($offer?->deal && $template?->id && ! $request->old() && $template->id === $offer->stage()?->template_id && $template->attach !== Template::SALE_DOCS
            && PaymentProofPdf::files($offer->deal)->isNotEmpty() && ($proof = PaymentProofPdf::render($offer->deal))) {
            $defaults['files'][] = $outbox->put(PaymentProofPdf::fileName($offer->deal), $proof);
        }

        // Документы поставщику по продаже (Совкомбанк, 23.11.2026): подписанный договор одним PDF.
        if ($offer?->deal && $template?->attach === Template::SALE_DOCS && ! $request->old() && ($signed = SignedContractPdf::render($offer->deal))) {
            $defaults['files'][] = $outbox->put(SignedContractPdf::fileName($offer->deal), $signed);
        }
        // Письма Совкомбанку о вывозе (23.11.2026, `Logistics\PickupLetters`): постоянные файлы шаблона (доверенность) и акт вывоза.
        if ($offer && $template?->exists && ! $request->old()) {
            foreach (\App\Logistics\PickupLetters::files($offer, $template) as $media) {
                $defaults['files'][] = $outbox->put($media->getCustomProperty('title') ?: $media->file_name, file_get_contents($media->getPath()));
            }
        }

        // Письмо с реквизитами без реквизитов не собираем (03.11.2026): кнопки шага нет, а по старой ссылке — словами почему.
        if ($offer?->deal && $template && preg_match('/\{\{ ?buyer ?\}\}/', (string) $template->body) && ($gap = $offer->deal->requisitesGap())) {
            abort(409, $gap.' — письмо с реквизитами пока не написать');
        }
        // Реквизиты покупателя-человека и ИП поставщику (03.11.2026, Альфа; ИП — 06.11.2026) — со сканом паспорта во вложении, одним PDF (05.11.2026,
        // владелец: «фотки со сканами, а должен pdf файл формировать и прикреплять»).
        if ($offer?->deal && $template && ! $request->old() && preg_match('/\{\{ ?buyer ?\}\}/', (string) $template->body)
            && ($party = $offer->deal->supplierInvoicee()) && $party->needsPassportScan() && ! $party->seller && ($scan = PassportPdf::render($party))) {
            $defaults['files'][] = $outbox->put(PassportPdf::fileName($party), $scan);
        }

        if ($supplierRequest && ! $request->old()) {
            $defaults['files'][] = $outbox->put($supplierRequest->filename(), $supplierRequest->xlsx());
        }
        // «Подтвердить оплату» — PDF оплаты во вложении, если он есть и ещё не уходил по этой машине.
        $sentByUs = $extra !== null ? Message::whereIn('thread_id', Thread::where('offer_id', $offer->id)->select('id'))->where('direction', Direction::Out)->with('attachments')->get() : collect();
        if ($extra !== null && ! $request->old() && ExtraRequest::proofNeeded($offer, $extra, $sentByUs) && ($proof = PaymentProofPdf::render($offer->deal))) {
            $defaults['files'][] = $outbox->put(PaymentProofPdf::fileName($offer->deal), $proof);
        }
        $closingFiles = [];
        if ($draft && ! $request->old()) {
            $closingFiles = $this->closingFiles($draft, $outbox, $actPdf, $send);
            $defaults['files'] = [...($defaults['files'] ?? []), ...$closingFiles];
        }

        return view('admin.mail.compose-frame', ['frame' => $request->header('Turbo-Frame'), 'account' => $account, 'accounts' => $accounts, 'thread' => $thread, 'parent' => $parent, 'report' => $release ? 'release' : null, 'defaults' => $defaults, 'invoice' => $invoice,
            'mode' => 'new', 'templates' => $extra !== null ? collect() : Template::where('scope', $this->scope)->letters()->orderBy('name')->get(), 'offer' => $offer, 'vehicle' => $vehicle, 'base' => $this->base,
            // Черновик после «Принять» (BidController::accepted): отправили — окно закрывается, человек идёт дальше по списку.
            'then' => $request->query('then') === 'close' ? 'close' : null,
            'requestDeals' => $supplierRequest?->deals->pluck('id')->implode(','),
            'pickupLetter' => $offer && preg_match('/^\d+:(?:date|act)$/', (string) $request->query('pickup')) ? (string) $request->query('pickup') : null,
            'closing' => $closing && ! $review ? $request->query('closing') : null, 'closingReplaces' => $closing[2]?->id ?? null, 'closingFiles' => $closingFiles, 'closingSend' => $send,
            'closingThread' => $closing ? $request->query('closing') : null,
            // «Спросить менеджера и запланировать письмо…» (27.11.2026, `Offers\PlannedLetters`): исход-вопрос или `wait` — уже спросили.
            'plan' => $offer && preg_match('/^(\d{1,9}|wait)$/', (string) $request->query('plan')) ? (string) $request->query('plan') : null,
            'extra' => $extra, 'extraCity' => $extraCity, 'extraAll' => $extra !== null ? Template::requests()->get() : collect(),
            // Менеджер сам просил «Доп. запрос…» (27.11.2026, `ExtraWant`): кто и его комментарий — строкой над галками.
            'extraWant' => $extra !== null ? \App\Offers\ExtraAsk::wanted($offer->deal)?->loadMissing('wanter') : null,
            // Просим подтвердить оплату, а платёжки в системе нет (платил ПРАЙМ) — прикладывают скрепкой.
            'extraNoProof' => $extra !== null && $extra->contains(fn (Template $t) => $t->attach === Template::PAY_PROOF) && (! $offer->deal || PaymentProofPdf::files($offer->deal)->isEmpty()),
            // Номер, который письмо показало: при отправке он меняется на настоящий, если успел смениться (полночь, другой отправил).
            'closingLabel' => $draft?->label()]);
    }

    /**
     * «2026-09|p71:park» → месяц, группа и исправляемый счёт для `Closing::draft` / `fix`; только деньги парковки.
     *
     * @return array{0: Carbon, 1: string, 2: ?Invoice}
     */
    private static function closingKey(string $key, mixed $replaces): array
    {
        abort_unless(request()->user()->parkMoney() && preg_match('/^(\d{4}-\d{2})\|(.{1,60})$/u', $key, $m), 404);
        $old = $replaces ? Invoice::ofPark()->where('state', InvoiceState::Issued)->where('paid', 0)->findOrFail((int) $replaces) : null;

        return [Carbon::createFromFormat('!Y-m', $m[1])->startOfMonth(), $m[2], $old];
    }

    /**
     * Файлы документа во вложения: счёт PDF и акт (файлы формы страховой у реестра, PDF акта у счёта на ТС).
     *
     * @return list<string> пути outbox
     */
    private function closingFiles(Invoice $doc, StoreOutboxFile $outbox, StorageActPdf $actPdf, string $send = 'both'): array
    {
        // Заполненные файлы акта временные: в outbox — копия, сами стираются.
        $files = $send === 'act' ? [] : [$outbox->put('Счет '.$doc->fileNo().'.pdf', app(InvoicePdf::class)->render($doc))];
        if ($send === 'invoice') {
            return $files;
        }
        if ($doc->exists) {
            $doc->load('media');
            foreach ($doc->getMedia('act') as $media) {
                $files[] = $outbox->put($media->file_name, file_get_contents($media->getPath()));
            }

            return $files;
        }
        if (! $doc->isRegistry()) {
            return [...$files, $outbox->put('akt-hraneniya-'.$doc->actFileNo().'.pdf', $actPdf->render($doc))];
        }
        foreach (app(RegistryAct::class)->files($doc) as $name => $path) {
            $files[] = $outbox->put($name, (string) file_get_contents($path));
            @unlink($path);
        }

        return $files;
    }

    /**
     * Редактор без окна (старая ссылка со страницы, открытой до выкладки; Telegram) — окно писем на той же странице, откуда
     * пришли (28.10.2026, владелец: «перебрасывает в раздел почта, бесит»): назад с `session('letters')`, его берёт
     * `x-mail.window` любой страницы. Пришли не со своей страницы — почта с этим окном (`?compose=`).
     */
    private function inWindow(Request $request)
    {
        $uri = $request->getRequestUri();
        // Предзагрузка и прочие фоновые запросы (наведение на уведомление «напишите письмо») окна не заказывают: метка `letters`
        // ставилась без клика и всплывала потом на чужих страницах (21.11.2026, письмо по другой машине у Андрея).
        if (\App\Http\Middleware\KeepFlashInBackground::background($request)) {
            return redirect($this->base.'?compose='.urlencode($uri));
        }
        $from = (string) $request->headers->get('referer');
        $same = $from !== '' && parse_url($from, PHP_URL_HOST) === $request->getHost() && ! str_contains($from, '/mail/new') && ! str_contains($from, '/reply/');

        return $same ? redirect($from)->with('letters', $uri) : redirect($this->base.'?compose='.urlencode($uri));
    }

    public function reply(Request $request, Thread $thread, Message $message, Composer $composer, StoreOutboxFile $outbox, ActPdf $act)
    {
        $this->guard($thread);
        abort_unless($message->thread_id === $thread->id, 404);
        // «Отмена» в ленте: вместо редактора снова кнопка в том же фрейме.
        if ($request->boolean('cancel') && str_starts_with((string) $request->header('Turbo-Frame'), 'reply')) {
            return view('admin.mail.reply-frame', ['message' => $message, 'base' => $this->base, 'frame' => $request->header('Turbo-Frame')]);
        }
        $message->load(['account.replyAccount', 'addresses', 'attachments']);
        $mode = $request->query('mode', 'reply');
        $defaults = $mode === 'forward' ? $composer->forward($message) : $composer->reply($message, $mode !== 'only');
        // «От» — ящики, с которых человек пишет (`Senders`, 21.11.2026); по умолчанию — тот, что отвечает за ящик письма (offer@ —
        // через deal@), если с него можно, иначе свой: модератор отвечает на письмо storage@ с deal@ новой веткой с In-Reply-To.
        $accounts = Senders::for($request->user());
        $sender = Senders::pick($request->user(), $message->account, $this->scope);
        abort_unless($sender, 404);

        if (! $request->header('Turbo-Frame')) {
            return $this->inWindow($request);
        }

        // Готовые ответы парковки (02.11.2026: управляющий отвечал из «Почты Mail.ru» одними и теми же фразами): пилюли над
        // письмом, подходящий по смыслу письма — первым; выбранный — текст его словами и, у «Фотоотчёта» и «АПП», акт с кадрами.
        $vehicle = $thread->vehicle;
        $templates = $this->scope === Scope::Park && $mode !== 'forward' ? Template::replies($message->intent) : collect();
        $template = $request->query('template') ? $templates->firstWhere('id', (int) $request->query('template')) : null;
        if ($template) {
            // Адресаты, тема и цитата — ответа, как без шаблона; шаблон даёт только текст.
            $defaults['body'] = $composer->replyWith($message, $sender, $template, $vehicle ? self::vehiclePlaceholders($vehicle) : [])['body'];
            // Акт выдачи — только выданной: у стоящей «АПП» уходит текстом, без пустого акта.
            if ($vehicle && isset(Template::ATTACH[$template->attach]) && ($template->attach === 'intake' ? $vehicle->accepted_at : $vehicle->released_at)) {
                $defaults['files'] = $this->actFiles($vehicle, $template->attach === 'intake', $outbox, $act);
            }
        }

        // Готовый текст ответа (04.11.2026, «Ответить» в шаге «Переоценка»: «Согласны на новую стоимость …») — над подписью.
        if ($this->scope === Scope::Offers && $mode === 'reply' && filled($request->query('text'))) {
            $defaults['body'] = Template::html(mb_substr((string) $request->query('text'), 0, 2000)).$defaults['body'];
        }

        return view('admin.mail.compose-frame', ['frame' => $request->header('Turbo-Frame'), 'account' => $sender, 'accounts' => $accounts, 'thread' => $thread, 'parent' => $message,
            'defaults' => $defaults, 'mode' => $mode, 'templates' => $templates, 'offer' => $thread->offer, 'vehicle' => $vehicle, 'base' => $this->base]);
    }

    public function send(Request $request, Composer $composer, MarkDoc $mark, MarkSupplierRequestSent $sentRequest, StoreOutboxFile $outbox, ActPdf $act)
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
        $account = Senders::for($request->user())->firstWhere('slug', $data['account']);
        abort_unless($account, 404);
        // Доп. запрос поставщику — что спросили, словами на момент отправки (`mail_messages.requests`).
        if ($this->scope === Scope::Offers && ! empty($data['extra'])) {
            $data['requests'] = ExtraRequest::record(ExtraRequest::picked($data['extra']), $data['extra_city'] ?? null);
        }
        if (! $composer->emails($data['to'])) {
            return redirect($retry ?: url()->previous())->withInput()->withErrors(['to' => 'Нужен хотя бы один адрес']);
        }
        // Письмо с реквизитами покупателя (шаблон с `{{ buyer }}`) — только проверенными (26.11.2026, `Offers\Actions\CheckBuyer`):
        // «Проверить и отправить реквизиты» в шаге сделки сначала проверяет, потом открывает это окно.
        if ($this->scope === Scope::Offers && ! empty($data['offer']) && ctype_digit((string) $request->input('template'))
            && preg_match('/\{\{ ?buyer ?\}\}/', (string) \App\Mail\Template::whereKey((int) $request->input('template'))->value('body')) === 1
            && ($deal = Offer::find($data['offer'])?->deal) && ! $deal->schemeOf()->paysCar() && ! $deal->isGarageUs() && $deal->contract?->needsBuyerCheck()) {
            return redirect($retry ?: url()->previous())->withInput()->withErrors(['body' => 'Реквизиты покупателя не проверены: «Проверить и отправить реквизиты» в шаге сделки']);
        }

        // «Запланировать» (27.11.2026, `Offers\PlannedLetters`): письмо не заводится, а ждёт «Беру в работу»; вопрос менеджеру —
        // этой же транзакцией. Ошибка — обратно в редактор.
        if ($this->scope === Scope::Offers && ! empty($data['offer']) && preg_match('/^(\d{1,9}|wait)$/', (string) $request->input('plan'))) {
            abort_unless($request->user()->canEditDeals(), 403);
            $plan = (string) $request->input('plan');
            $offer = Offer::findOrFail($data['offer']);
            $ask = $plan === 'wait' ? null : \App\Workflow\Outcome::where('actor', \App\Workflow\Actor::Staff->value)->findOrFail((int) $plan);
            try {
                $planned = app(\App\Offers\PlannedLetters::class)->plan($offer, $ask, $account,
                    $data + ['template' => ctype_digit((string) $request->input('template')) ? (int) $request->input('template') : null], $request->user());
            } catch (ValidationException $e) {
                return redirect($retry ?: url()->previous())->withInput()->withErrors($e->errors());
            }

            return $inWindow ? redirect("{$this->base}/planned/{$planned->id}/done")
                : redirect('/offers/'.$offer->number)->with('toast', $ask ? 'Письмо запланировано, менеджера спросили' : 'Письмо запланировано');
        }

        // Родитель — письмо, ветку которого человеку видно: своей стороны или другой в окне машины (`across`). Ответ уходит с
        // выбранного ящика («От»); с ящика не той ветки — новой веткой с In-Reply-To (`Composer::create`).
        $parent = ! empty($data['parent']) ? Message::with(['thread.account', 'attachments', 'account'])->find($data['parent']) : null;
        $parent = $parent?->thread && ($parent->thread->account->scope === $this->scope || $this->across($parent->thread)) ? $parent : null;
        $thread = ! empty($data['thread']) ? Thread::where('account_id', $account->id)->find($data['thread']) : null;
        // Повторная отправка из того же редактора после «не ушло» (`compose_send_controller`): неудачное письмо уступает место,
        // новое ложится в его ветку.
        $dropped = app(DropFailed::class)(ctype_digit((string) $request->input('replaces')) ? (int) $request->input('replaces') : null, $request->user());
        $thread ??= $dropped && $dropped->account_id === $account->id && $dropped->messages()->exists() ? $dropped : null;

        // Файл, которого нет в outbox, письмо не заводит (`Composer::attach`, 29.10.2026: «Письмо пришло без вложения»), а
        // «Отправить», пока файлы грузятся, редактор не пускает (`photos_controller`) — возвращаемся в редактор с ошибкой.
        // Живой документ месяца фиксируется этой отправкой той же транзакцией: файлы черновика меняются на файлы
        // записанного счёта, номер черновика в теме и тексте — на настоящий; письмо не создалось — фиксации нет.
        $fixed = null;
        try {
            $message = DB::transaction(function () use ($data, $composer, $account, $parent, $request, $thread, $outbox, &$fixed) {
                if ($this->scope === Scope::Park && ! empty($data['closing'])) {
                    [$month, $group, $old] = self::closingKey($data['closing'], $data['closing_replaces'] ?? null);
                    $label = $data['closing_label'] ?? null;
                    $fixed = app(FixClosingDocument::class)($month, $group, $request->user(), $old);
                    $drop = $data['closing_files'] ?? [];
                    $send = array_key_exists((string) ($data['closing_send'] ?? ''), Template::SENDS) ? $data['closing_send'] : 'both';
                    $data['files'] = [...array_values(array_diff($data['files'] ?? [], $drop)), ...$this->closingFiles($fixed, $outbox, app(StorageActPdf::class), $send)];
                    foreach (['subject', 'body'] as $f) {
                        $data[$f] = $label ? str_replace($label, $fixed->label(), (string) ($data[$f] ?? '')) : ($data[$f] ?? null);
                    }
                    $data['invoice'] = $fixed->id;
                }

                $message = $composer->create($account, $data, $parent, $request->user(), $thread);
                // Ветка нового письма — машине той же транзакцией (19.11.2026): задача отправки встаёт в очередь внутри
                // `create`, и раньше воркер успевал отправить письмо до привязки ветки — шаг письмо не засчитывал
                // (`MarkSupplierLetter` ищет машину по ветке).
                if ($this->scope === Scope::Offers && ! empty($data['offer']) && $message->thread && ! $message->thread->offer_id) {
                    $message->thread->update(['offer_id' => $data['offer']]);
                }

                return $message;
            });
        } catch (ValidationException $e) {
            if ($retry) {
                return redirect($retry)->withErrors($e->errors())->withInput();
            }
            throw $e;
        }
        // Ветка письма с документом месяца — его переписка (сверка и финал): «месяц|группа» (`mail_threads.closing_key`).
        if ($this->scope === Scope::Park && ! empty($data['closing_thread']) && $message->thread && preg_match('/^\d{4}-\d{2}\|.{1,60}$/u', $data['closing_thread'])) {
            $message->thread->update(['closing_key' => $data['closing_thread']]);
        }
        if (! empty($fixed)) {
            $data['invoice'] = $fixed->id;
            // Документ месяца — с письмом, которым ушёл: история карточки «Отправлен письмом на …».
            $fixed->update(['sent_message_id' => $message->id]);
        }
        // Письмо по шаблону шага, а сделка ещё не на этом шаге (Т-Страхование: «готовность к покупке» до «Беру в работу») —
        // в историю тем же «письмом поставщику» с шаблоном: войдёт на шаг — засчитается (`MarkSupplierLetter::already`).
        if ($this->scope === Scope::Offers && ! empty($data['offer']) && ctype_digit((string) $request->input('template'))) {
            \App\Offers\Listeners\MarkSupplierLetter::early($message, (int) $data['offer'], (int) $request->input('template'));
        }
        if ($this->scope === Scope::Park && ! empty($data['vehicle']) && $message->thread && ! $message->thread->vehicle_id) {
            $message->thread->update(['vehicle_id' => $data['vehicle']]);
        }
        // Ушёл акт и фото — бумаги «акты» и «фото» вендору отмечаются отправленными этим письмом, в ленте ТС — отчёт.
        if ($this->scope === Scope::Park && ! empty($data['vehicle']) && ($vehicle = Vehicle::find($data['vehicle']))) {
            $sent = collect($message->attachments()->pluck('filename'));
            // Наш акт — «akt-…» (прежние) или «Акт приёма / выдачи …» (`ActPdf::filename`).
            $act = $sent->first(fn ($f) => preg_match('/^(akt-|Акт (приёма|выдачи) )/u', (string) $f) === 1);
            $report = ($data['report'] ?? null) === 'release';
            $kinds = array_filter([$act || $report ? DocKind::HandoverAct : null, $act ? DocKind::StorageAct : null, $sent->contains(fn ($f) => preg_match('/^(priem|vydacha)-\d+\./', $f)) ? DocKind::Photos : null]);
            foreach ($vehicle->docs()->where('direction', 'out')->where('state', DocState::Pending)->whereIn('kind', $kinds)->get() as $doc) {
                $mark($doc, $request->user(), DocState::Sent, threadId: $message->thread_id);
            }
            // Письмо о выдаче (`report=release`) — отчёт о выдаче, что бы ни приложили (сканы своими именами, 06.11.2026).
            if ($act || ($data['report'] ?? null) === 'release') {
                $vehicle->log(EventType::ReportSent, $request->user(), ['what' => ($data['report'] ?? null) === 'release' || preg_match('/^(akt-vydachi|Акт выдачи )/u', (string) $act) === 1 ? 'Акт выдачи' : 'Акт приёма', 'thread' => $message->thread_id]);
            }
            // Ушёл счёт — отметка на нём и в ленте ТС.
            if (! empty($data['invoice']) && $request->user()->parkMoney() && ($invoice = Invoice::where('vehicle_id', $vehicle->id)->find($data['invoice']))) {
                $invoice->update(['sent_at' => now(), 'sent_by' => $request->user()->id, 'sent_message_id' => $message->id]);
                $vehicle->log(EventType::ReportSent, $request->user(), ['what' => 'Счёт '.$invoice->label(), 'thread' => $message->thread_id]);
            }
        }
        // Ушёл счёт-реестр — отметка на нём и отчёт в ленте каждой его ТС.
        if ($this->scope === Scope::Park && ! empty($data['invoice']) && $request->user()->parkMoney() && ($registry = Invoice::whereNull('vehicle_id')->whereNotNull('month')->find($data['invoice']))) {
            $registry->update(['sent_at' => now(), 'sent_by' => $request->user()->id, 'sent_message_id' => $message->id]);
            foreach ($registry->registryVehicles() as $v) {
                $v->log(EventType::ReportSent, $request->user(), ['what' => 'Реестр '.$registry->label(), 'thread' => $message->thread_id]);
            }
        }

        // Ответ на письмо переоценки — поставщику ответили (`RepriceFlow`, 04.11.2026). Доп. запрос — не ответ на переоценку.
        if ($this->scope === Scope::Offers && empty($data['requests'])) {
            Reprice::answeredBy($message, $parent, $request->user());
        }
        // Доп. запрос ушёл — в «Историю» машины и менеджеру сделки в чат.
        if ($this->scope === Scope::Offers && ! empty($data['requests']) && ! empty($data['offer']) && ($asked = Offer::find($data['offer']))) {
            app(LogExtraRequest::class)($asked, $message, $request->user());
        }
        // Письмо о вывозе ушло — дело вывоза закрыто (`Logistics\PickupLetters`).
        if ($this->scope === Scope::Offers && ! empty($data['pickup_letter'])) {
            [$tid, $kind] = explode(':', $data['pickup_letter']);
            if ($t = \App\Logistics\Transport::find((int) $tid)) {
                app(\App\Logistics\Actions\MarkPickupLetter::class)($t, $kind, $request->user(), $message);
            }
        }
        // Заявка поставщику ушла — сделки из её файла идут дальше «Заявка отправлена».
        $requested = $this->scope === Scope::Offers && ! empty($data['request_deals'])
            ? $sentRequest(array_map('intval', explode(',', $data['request_deals'])), $message, $request->user()) : null;

        if ($inWindow) {
            // Редактор не закрывается, пока письмо не ушло (15.11.2026, начальник «постоянно переживает, отправлено письмо или
            // нет»; владелец: «как в нормальных почтовых клиентах»): `compose_send_controller` узнаёт ответ по адресу
            // `messages/{id}/sent`, не даёт Turbo его рисовать и ждёт `send_state = sent` — галка на кнопке «Отправить».
            return redirect("{$this->base}/messages/{$message->id}/sent".($request->input('then') === 'close' ? '?then=close' : ''))
                ->with('mail-requested', $requested);
        }

        // Не из окна (форма без фрейма) — ветка письма.
        return redirect("{$this->base}/{$message->thread_id}")->with('toast', 'Письмо в очереди');
    }

    /**
     * Подсказки адресатов в «Кому» и «Копии» (`x-mail.recipients`, 28.10.2026): с кем переписывалась своя сторона — недавние
     * сверху, и контакты вендоров своей стороны. Свои ящики не подсказываются. JSON `[{email, name, vendor}]`.
     */
    public function addresses(Request $request)
    {
        $q = mb_strtolower(trim((string) $request->query('q')));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $like = '%'.addcslashes($q, '%_\\').'%';
        $own = Account::pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
        $rows = DB::table('mail_addresses as a')->join('mail_messages as m', 'm.id', '=', 'a.message_id')
            ->join('mail_accounts as acc', 'acc.id', '=', 'm.account_id')->where('acc.scope', $this->scope->value)
            ->where(fn ($w) => $w->whereRaw('lower(a.email) like ?', [$like])->orWhereRaw('lower(a.name) like ?', [$like]))
            ->whereNotIn(DB::raw('lower(a.email)'), $own)
            ->groupBy(DB::raw('lower(a.email)'))->orderByRaw('max(m.date_at) desc')->limit(8)
            ->get([DB::raw('lower(a.email) as email'), DB::raw("max(nullif(a.name, '')) as name")]);
        $contacts = Contact::with('vendor:id,name')->whereNotNull('email')->whereIn('role', ContactRole::side($this->scope === Scope::Offers))
            ->where(fn ($w) => $w->whereRaw('lower(email) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like]))->limit(8)->get();
        $out = collect();
        foreach ($contacts as $c) {
            $out[mb_strtolower($c->email)] = ['email' => mb_strtolower($c->email), 'name' => $c->name ?: null, 'vendor' => $c->vendor?->name];
        }
        foreach ($rows as $r) {
            $out[$r->email] ??= ['email' => $r->email, 'name' => $r->name, 'vendor' => null];
        }

        return response()->json($out->values()->take(8));
    }

    public function file(Request $request, StoreOutboxFile $store)
    {
        $request->validate(['file' => ['required', 'file', 'max:25600']]);
        $path = $store($request->file('file'));

        return Stream::view('admin.mail.file-stream', ['path' => $path, 'name' => $request->file('file')->getClientOriginalName()]);
    }

    /**
     * Документ из фото во вложения письма (06.11.2026, Сергей: акт выдачи проще сфоткать): снимки одним PDF под названием,
     * номер убытка ТС — хвостом (`PhotosPdf`). Ответ — строка вложения `admin.mail.file-row`, её ставит `photo_pdf_controller`.
     */
    public function filePdf(Request $request, StoreOutboxFile $store, PhotosPdf $photos)
    {
        $data = $request->validate([
            'pages' => ['required', 'array', 'min:1', 'max:'.PhotosPdf::MAX_PAGES],
            'pages.*' => ['required', 'file', 'max:40960'],
            'name' => ['required', 'string', 'max:120'],
            'vehicle' => ['nullable', 'integer'],
        ], ['name.required' => 'Как назвать документ?']);
        $vehicle = $this->scope === Scope::Park && ! empty($data['vehicle']) ? Vehicle::find($data['vehicle']) : null;
        try {
            $made = $photos->make($request->file('pages'), $data['name'], $vehicle);
        } catch (Throwable $e) {
            return response()->json(['message' => 'PDF не собрался: '.$e->getMessage()], 422);
        }
        $path = $store->put($made['name'], $made['pdf']);

        return response()->json(['name' => $made['name'], 'pages' => $made['pages'], 'html' => view('admin.mail.file-row', ['path' => $path, 'name' => $made['name']])->render()]);
    }

    public function attachment(Request $request, Attachment $attachment, PhotoIngest $photos)
    {
        $attachment->load('message.account');
        $this->guardMessage($attachment->message);
        // Файл — из outbox, из закреплённых или из ящика через кэш; отдаётся с диска, не через память.
        $file = $attachment->file();
        abort_if($file === null, 404, 'Файла нет: письмо удалено из ящика');
        $mime = $attachment->mime ?: 'application/octet-stream';
        $name = $attachment->filename;
        // Файл из архива (`?entry=N`, шторка документов): запись — во временный файл, дальше как обычное вложение.
        if ($request->has('entry')) {
            // Кадр архива в ленте письма (`?entry=N&thumb=1|large=1`): миниатюра считается раз и живёт в cache/mail — архив
            // в 20 МБ не распаковывается заново на каждую плитку.
            $size = $request->boolean('thumb') ? 320 : ($request->boolean('large') ? PhotoIngest::MAX_DIMENSION : null);
            $cached = $size ? ($size === 320 ? 'thumb' : 'large')."-{$attachment->id}-e".(int) $request->query('entry') : null;
            if ($cached && is_file($small = Storage::disk('cache')->path("mail/{$cached}.webp"))) {
                return response()->file($small, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=86400']);
            }
            $one = app(ArchivePhotoExtractor::class)->entryFile($file, (int) $request->query('entry'));
            abort_if($one === null, 404, 'Файла нет в архиве');
            [$file, $name] = [$one['path'], $one['name']];
            $mime = (new MimeTypes)->getMimeTypes(strtolower(pathinfo($name, PATHINFO_EXTENSION)))[0] ?? 'application/octet-stream';
            if (preg_match('/\.hei[cf]$/i', $name)) {
                try {
                    $jpeg = $photos->toJpeg($file);
                    @unlink($file);
                    [$file, $mime] = [$jpeg, 'image/jpeg'];
                } catch (Throwable) {
                }
            }
            if ($cached && Docs::type($mime, $name) === 'image' && ($small = Thumb::of($file, false, $cached, $size))) {
                @unlink($file);

                return response()->file($small, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=86400']);
            }
            // Временный файл снимается сразу после ответа: под Octane shutdown-функции ждали бы конца воркера.
            if ($request->boolean('preview')) {
                try {
                    return OfficePreview::response($file, $name);
                } finally {
                    @unlink($file);
                }
            }
            $inline = in_array(Docs::type($mime, $name), ['pdf', 'image'], true);

            return response()->file($file, [
                'Content-Type' => $mime,
                'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($name),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, max-age=86400',
            ])->deleteFileAfterSend();
        }
        if ($request->boolean('preview')) {
            return OfficePreview::response($file, $name);
        }
        // HEIC с айфона браузер не показывает — отдаём JPEG (он же — исходник для миниатюры и просмотра).
        if ($attachment->isHeic()) {
            $jpeg = Storage::disk('cache')->path("mail/jpeg-{$attachment->id}.jpg");
            if (! is_file($jpeg) && $photos->isHeic($file)) {
                try {
                    rename($photos->toJpeg($file), $jpeg);
                } catch (Throwable) {
                    // Не перекодировался — отдаём как есть, скачать можно.
                }
            }
            if (is_file($jpeg)) {
                [$file, $mime, $name] = [$jpeg, 'image/jpeg', preg_replace('/\.hei[cf]$/i', '', $name).'.jpg'];
            }
        }
        // ?thumb — миниатюра для ленты в письме, ?large — кадр для просмотра во весь экран (1600, как у медиатеки:
        // с камеры приходят мегабайты). Считаются раз, живут в cache/mail (storage:gc чистит по сроку).
        $size = $request->boolean('thumb') ? 320 : ($request->boolean('large') ? PhotoIngest::MAX_DIMENSION : null);
        if ($size && $attachment->isImage() && $mime !== 'image/svg+xml'
            && ($small = Thumb::of($file, false, ($size === 320 ? 'thumb' : 'large')."-{$attachment->id}", $size))) {
            return response()->file($small, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=86400']);
        }
        // ?thumb у PDF — первая страница картинкой (плитка документа в «✨ Распознать»), тоже раз и в cache/mail.
        if ($size === 320 && $attachment->isPdf()) {
            $small = Thumb::of($file, true, "thumb-{$attachment->id}");
            abort_unless($small, 404);

            return response()->file($small, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=86400']);
        }
        // SVG — не картинка, а документ со скриптами: только на скачивание.
        $inline = ($attachment->isImage() && $mime !== 'image/svg+xml') || $attachment->isPdf();

        return response()->file($file, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /** «Ответ не нужен» внизу ленты: вопрос решили иначе — ветка уходит из «Требуют внимания», но не в архив. */
    public function answered(Request $request, Thread $thread, Threads $threads, MarkThreadRead $markRead)
    {
        $this->guard($thread);
        $threads->answered($thread, $request->user());
        $markRead($thread);

        return back()->with('toast', 'Ответ не нужен');
    }

    public function unread(Thread $thread, MarkThreadRead $markRead)
    {
        $this->guard($thread);
        $markRead($thread, false);

        return redirect($this->base)->with('toast', 'Не прочитано');
    }

    public function flag(Message $message)
    {
        $this->guardMessage($message);
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
        // Модератор перевязывает письма только между предложениями, которые ему можно открыть: опубликованное и закупку
        // он не трогает ни привязкой, ни отвязкой.
        abort_unless(! $thread->offer || $thread->offer->isEditableBy($request->user()), 404);
        $number = (int) preg_replace('/\D/', '', (string) $request->input('number'));
        if ($number === 0) {
            $link->unlink($thread);

            return $back()->with('toast', 'Отвязано');
        }
        $offer = OfferNumber::find($number);
        if (! $offer || ! $offer->isEditableBy($request->user())) {
            return $back()->withErrors(['number' => 'Нет такого предложения']);
        }
        $link($thread, $offer);

        return $back()->with('toast', "Привязано к № {$offer->number}");
    }

    /**
     * «Выбрать вендора» у письма, где вендор не узнан (16.10.2026, владелец): выбрать из своих или завести нового — и письма
     * с этого адреса (или всего домена, если он не общий почтовый) теперь его (`BindSender`). Вендоров правит админ CRM,
     * на парковке — администратор парковки.
     */
    public function vendor(Request $request, Thread $thread, BindSender $bind)
    {
        $this->guard($thread);
        $park = $this->scope === Scope::Park;
        abort_unless($park ? $request->user()->isParkAdmin() : $request->user()->canManageCrm(), 403);
        $data = $request->validate([
            'mode' => ['required', 'in:pick,new'],
            'vendor_id' => ['required_if:mode,pick', 'nullable', 'integer'],
            'name' => ['required_if:mode,new', 'nullable', 'string', 'max:80'],
            'kind' => ['nullable', Rule::enum(Kind::class)],
            'sender' => ['required', 'email', 'max:255'],
            'domain' => ['nullable', 'boolean'],
        ], ['vendor_id.required_if' => 'Выберите вендора', 'name.required_if' => 'Название вендора']);
        // Свой адрес (ящики, почта сотрудников) вендору не отдаётся — как и кнопка `VendorPick::of` его не предлагает.
        if (in_array(mb_strtolower(trim($data['sender'])), [...Message::ownEmails(), ...Message::ownMailboxes()], true)) {
            return back()->withErrors(['sender' => 'Это наш адрес']);
        }
        $sides = fn () => $park ? Vendor::onPark() : Vendor::query()->whereNull('crm_vendor_id');
        if ($data['mode'] === 'pick') {
            $vendor = $sides()->find($data['vendor_id']);
            if (! $vendor) {
                return back()->withErrors(['vendor_id' => 'Выберите вендора'])->withInput();
            }
        } else {
            $vendor = Vendor::findOrCreateByName($data['name'], ['kind' => $data['kind'] ?? null, 'on_park' => $park ?: null]);
            if ($park && ! $vendor->on_park) {
                $vendor->forceFill(['on_park' => true])->save();
            }
        }
        $read = $bind($vendor, $data['sender'], $this->scope, (bool) ($data['domain'] ?? false));
        $back = $request->header('Turbo-Frame') === 'letters-frame' ? redirect("{$this->base}/{$thread->id}/window") : back();
        $toast = $vendor->wasRecentlyCreated ? "Вендор «{$vendor->name}» заведён"
            : ($bind->movedFrom ? "Адрес перенесён от «{$bind->movedFrom}» к «{$vendor->name}»" : "Письма с адреса теперь у «{$vendor->name}»");

        return $back->with('toast', $toast.($read > 1 ? ", писем: {$read}" : ''))
            ->with('toast-href', $vendor->wasRecentlyCreated ? ($park ? "/vendors/{$vendor->id}" : "/settings/vendors/{$vendor->id}") : null);
    }

    public function reparse(Message $message)
    {
        $this->guardMessage($message);
        ParseMessage::dispatch($message->id);

        return back()->with('toast', 'Разбор поставлен в очередь');
    }

    /**
     * Куда ведёт отправка из окна писем (15.11.2026). Редактор (`compose_send_controller`) этот ответ не рисует: ждёт
     * настоящей отправки и сам закрывает окно или открывает ветку. Без него (старая вкладка, нет скриптов) — как было до
     * галки: после «Принять» (`then=close`) окно закрывается, иначе ветка в окне.
     */
    public function sent(Request $request, Message $message)
    {
        $this->guardMessage($message);
        abort_unless($message->isOutgoing(), 404);
        $requested = session('mail-requested');
        // Редактор ждёт галки (`compose_send_controller` шлёт заголовок, fetch несёт его и через редирект `send`): состояние сразу.
        if ($request->hasHeader('X-Compose-Send')) {
            return response()->json(['id' => $message->id] + $message->sendStatus() + ['thread' => "{$this->base}/{$message->thread_id}/window",
                'toast' => $requested !== null ? "Заявка поставщику: {$requested} ТС" : null]);
        }
        $window = redirect("{$this->base}/{$message->thread_id}/window");
        if ($request->query('then') === 'close') {
            return $window->with('toast', $requested !== null ? "Заявка поставщику в очереди, {$requested} ТС" : 'Письмо вендору в очереди')->with('letters-close', true);
        }

        return $window->with('toast', 'Письмо в очереди');
    }

    /** Письмо запланировано (`PlannedLetters::plan`): редактору — тост, окно закрывается (`compose_send_controller`). */
    public function planned(Request $request, \App\Offers\PlannedLetter $planned)
    {
        abort_unless($request->user()->canEditDeals() && $planned->created_by === $request->user()->id, 404);

        return response()->json(['toast' => 'Письмо запланировано: уйдёт, когда менеджер возьмёт в работу']);
    }

    /** Состояние отправки письма для редактора, что ждёт галки (`compose_send_controller`); `thread` — ветка в окне после. */
    public function state(Message $message)
    {
        $this->guardMessage($message);
        abort_unless($message->isOutgoing(), 404);

        return response()->json($message->fresh()->sendStatus() + ['thread' => "{$this->base}/{$message->thread_id}/window"]);
    }

    public function resend(Message $message)
    {
        $this->guardMessage($message);
        abort_unless($message->isOutgoing() && $message->send_state === SendState::Failed, 404);
        $message->forceFill(['send_state' => SendState::Queued, 'send_error' => null])->save();
        SendMessage::dispatch($message->id);

        return back()->with('toast', 'Отправляем снова');
    }

    /**
     * «Проверить почту» (04.10.2026, начальник: письма «очень долго падают в систему»): ящики своей стороны забираются
     * тут же, не очередью — новое сразу в списке, и в тосте сколько пришло.
     */
    public function sync(Sync $sync)
    {
        $new = 0;
        $failed = false;
        foreach (Account::where('scope', $this->scope)->where('is_active', true)->get() as $account) {
            try {
                // Приём почты — служебная работа: ветки и цепочки ищутся по всему ящику, не по городу управляющего.
                $new += City::unscoped(fn () => $sync->account($account, wait: 30));
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }
        }

        return back()->with('toast', match (true) {
            $failed => 'Ящик не ответил, попробуйте ещё раз',
            $new > 0 => 'Новых писем: '.$new,
            default => 'Новых писем нет',
        });
    }

    /** Подстановки шаблона из оффера. */
    public static function placeholders(Offer $offer): array
    {
        $offer->loadMissing(['vendor', 'deal.buyer']);

        return [
            'number' => $offer->number,
            'car' => $offer->titleWithYear(),
            'vin' => $offer->vin ?? '',
            'claim_ref' => $offer->claim_ref ?? '',
            // Закупочная с наценкой к оценочной — «Прошу согласовать реализацию за 756 000 ₽ (+10%)» (08.10.2026).
            'floor' => Pricing::floorLabel($offer->floor_price, $offer->value, Pricing::rulesFor($offer)),
            // Письмо поставщику — сколько получает он: наша закупка (`supplierCost`) или то, что покупатель платит ему сам
            // («Страховой напрямую»), а не цена менеджера (18.10.2026: «оплачен на сумму» уходило Альфе с ценой сделки).
            'price' => ($p = $offer->deal ? ($offer->deal->paysSelection() ? $offer->deal->supplierPayment() : $offer->deal->supplierCost()) : null) ? Money::plain($p) : ($offer->asking_price ? Money::plain($offer->asking_price) : ''),
            'manager' => $offer->deal?->buyer?->name ?? '',
            'insurer' => $offer->vendor?->name ?? '',
            'today' => now()->translatedFormat('j F Y'),
            // Реквизиты для счёта поставщика (03.11.2026, Альфа): покупатель по договору, где платим мы — наше юрлицо.
            'buyer' => $offer->deal?->supplierInvoicee()?->requisitesText() ?? '',
            // Запись на подписание у Альфы (11.11.2026): время, что выбрал менеджер, и адрес филиала.
            // Не в Москве — без записи (25.11.2026): «Подписание договора в г. Белгород, в рабочее время без записи».
            'signing' => $offer->deal?->signing_at ? Ago::at($offer->deal->signing_at, true, words: false)
                : ($offer->deal && ! $offer->deal->signsByAppointment() ? \App\Offers\Actions\SkipSigningBooking::in($offer->deal->signingCity()->value('name')).', в рабочее время без записи' : ''),
            // Город подписания (25.11.2026) — письму поставщику с документом об оплате: «г. Белгород».
            'signing_city' => ($c = $offer->deal?->signingCity()->value('name')) ? 'г. '.$c : '',
            // Фраза в письмо с документом об оплате, только когда город указан: « Подписание договора планируется в г. Белгород».
            'signing_note' => $c ? ' Подписание договора планируется в г. '.$c.'.' : '',
            'signing_address' => $offer->deal?->signing_address ?? '',
            // Время записи словами для письма поставщику (11.11.2026): «в четверг 12.11 в 15:00».
            // Выбрано одно время — им, иначе дни и время, что назвал менеджер: «в четверг 12.11 или в пятницу 13.11, в 11:00 или 15:00».
            'signing_when' => $offer->deal ? ($offer->deal->signing_at ? Signing::words($offer->deal->signing_at) : Signing::wishWords($offer->deal)) : '',
            // Документы поставщику (Совкомбанк, 23.11.2026): номер договора комиссии и наша платёжка ему — по выписке.
            // Только читаем: `DealContract::for` завёл бы договор сделке, у которой его быть не должно.
            'commission_no' => ($offer->deal?->contract?->commission_no ?: $offer->claim_ref) ?? '',
            ...self::supplierPayment($offer),
        ] + \App\Logistics\PickupLetters::placeholders($offer);
    }

    /** Наш последний платёж поставщику по сделке из выписки: «платёжным поручением № 1341 от 16.11.2026». @return array{payment: string} */
    private static function supplierPayment(Offer $offer): array
    {
        $tx = $offer->deal ? \App\Billing\Bank\Transaction::where('deal_id', $offer->deal->id)->where('direction', 'out')
            ->whereIn('counterparty_inn', \App\Offers\Actions\StampDealMoney::supplierInns($offer->deal))->latest('booked_at')->first() : null;

        return ['payment' => $tx ? trim('платёжным поручением'.($tx->doc_number ? ' № '.$tx->doc_number : '').($tx->booked_at ? ' от '.$tx->booked_at->format('d.m.Y') : '')) : ''];
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

    /** Абзац про выдачу по QR — перед «С уважением», а без него — в конец. */
    private static function withPickupNotice(string $body): string
    {
        $at = mb_strpos($body, 'С уважением');

        return $at === false ? rtrim($body)."\n\n".Template::PICKUP_NOTICE : rtrim(mb_substr($body, 0, $at))."\n\n".Template::PICKUP_NOTICE."\n\n".mb_substr($body, $at);
    }

    /**
     * Акт и до 12 кадров приёма или выдачи во вложения письма о машине: письмо о выдаче (`act=release`) и готовые ответы
     * парковки с `attach` (02.11.2026, «Фотоотчёт по приёму», «Подписанный АПП»). @return list<string> пути в outbox
     */
    private function actFiles(Vehicle $vehicle, bool $intake, StoreOutboxFile $outbox, ActPdf $act): array
    {
        $stage = $intake ? PhotoStage::Intake : PhotoStage::Release;
        // Кадров этапа нет — любые видимые, под именем этапа: по нему письмо отмечает бумагу «фото» отправленной (`send`).
        $shots = $this->shotFiles($vehicle, $stage, $outbox) ?: $this->shotFiles($vehicle, null, $outbox, $intake ? 'priem' : 'vydacha');

        return [$outbox->put($act->filename($vehicle, $intake), $act->render($vehicle, $intake)), ...$shots];
    }

    /**
     * До 12 кадров этапа (`null` — любые видимые) во вложения. Сначала снятое на парковке, потом приехавшее из писем:
     * вендору нужны наши кадры, а не его же. Файлы из писем у выданной стирает фоновая очистка (`PurgeReleasedLetters`) —
     * в письмо их не берём: файл мог исчезнуть посреди сборки. @return list<string> пути в outbox
     */
    private function shotFiles(Vehicle $vehicle, ?PhotoStage $stage, StoreOutboxFile $outbox, ?string $prefix = null): array
    {
        $doomed = $vehicle->state === VehicleState::Released ? PurgeLetters::media($vehicle)->pluck('id')->all() : [];
        $pool = ($stage ? $vehicle->photos()->filter(fn ($m) => PhotoStage::of($m) === $stage) : $vehicle->visiblePhotos())
            ->reject(fn ($m) => in_array($m->id, $doomed, true))
            ->sortBy(fn ($m) => $m->getCustomProperty('source') === 'mail' ? 1 : 0)->filter(fn ($m) => is_file($m->getPath()))->values();
        // Своё имя у каждого этапа: два этапа в одном письме не дают двух «foto-1.jpg».
        $prefix ??= match ($stage) {
            PhotoStage::Intake => 'priem', PhotoStage::Release => 'vydacha', PhotoStage::Vendor => 'strahovaya', PhotoStage::Pickup => 'pogruzka', null => 'foto'
        };
        $files = [];
        foreach ($pool->take(12) as $i => $m) {
            $contents = file_get_contents($m->getPath());
            // Во вложение уходит ужатый кадр, а `sha` у него от исходника: запоминаем отпечаток отправленного,
            // иначе это же письмо из «Отправленных» принесёт наши фото обратно вторым экземпляром.
            if ($m->getCustomProperty('sent_sha') !== ($sha = hash('sha256', $contents))) {
                $m->setCustomProperty('sent_sha', $sha)->save();
            }
            $files[] = $outbox->put($prefix.'-'.($i + 1).'.'.pathinfo($m->file_name, PATHINFO_EXTENSION), $contents);
        }

        return $files;
    }

    /**
     * Письмо, на которое отвечает письмо о выдаче: о реализации ТС (`sold_message_id`), без него — заявка о приёме (письмо
     * первой заявки на приём). Только входящее письмо парковки.
     */
    private static function releaseParent(Vehicle $vehicle): ?Message
    {
        $intake = $vehicle->requests()->where('type', RequestType::Intake)->whereNotNull('letter_id')->orderBy('id')->value('letter_id');
        foreach (array_filter([$vehicle->sold_message_id, $intake]) as $id) {
            $m = Message::with(['account', 'addresses', 'thread'])->where('direction', Direction::In)->find($id);
            if ($m && $m->account?->scope === Scope::Park) {
                return $m;
            }
        }

        return null;
    }

    /**
     * Копия письма о выдаче — все, кому был адресован изначальный запрос (06.11.2026, Сергей): отправитель и адресаты «Кому» и
     * «Копия» заявки о приёме и письма, на которое отвечаем, без наших ящиков и без самого адресата. @return list<string>
     */
    private static function requestCopies(Message $parent, Vehicle $vehicle, string $to): array
    {
        $intake = $vehicle->requests()->where('type', RequestType::Intake)->whereNotNull('letter_id')->orderBy('id')->value('letter_id');
        $first = $intake ? Message::with('addresses')->find($intake) : null;
        $ours = Account::pluck('email')->map(fn ($e) => mb_strtolower(trim((string) $e)))->filter()->all();
        $skip = [...$ours, ...array_map(fn ($a) => mb_strtolower(trim($a)), explode(',', $to))];
        $out = [];
        foreach (array_filter([$first, $parent]) as $m) {
            $m->loadMissing('addresses');
            foreach ([AddressKind::From, AddressKind::To, AddressKind::Cc] as $kind) {
                foreach ($m->addressesOfKind($kind) as $a) {
                    $e = mb_strtolower(trim($a->email));
                    if ($e !== '' && ! in_array($e, $skip, true) && ! in_array($e, $out, true)) {
                        $out[] = $e;
                    }
                }
            }
        }

        return $out;
    }

    public static function vehiclePlaceholders(Vehicle $vehicle): array
    {
        $vehicle->loadMissing(['vendor', 'yard', 'inspections']);

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
            'pickup_link' => $vehicle->vendor?->release_by_qr ? $vehicle->pickupUrl() : '',
        ];
    }

    /**
     * Последнее письмо вендора по предложению из всех его веток стороны «Предложения» — на него отвечает письмо этапа.
     * Пересылка сотрудника с личного ящика — тоже письмо вендора (`Message::isFromVendor`).
     */
    /** Письмо поставщика, на которое отвечать, — одна дверь с автоматом (`Offers\SupplierAddress`, 19.11.2026). */
    private static function vendorLetter(Offer $offer): ?Message
    {
        return \App\Offers\SupplierAddress::letter($offer);
    }

    private function sendRules(Request $request): array
    {
        return $request->validate([
            // Ящик — только тот, с которого человек пишет (`Senders`).
            'account' => ['required', Rule::in(Senders::for($request->user())->pluck('slug')->all())],
            'parent' => ['nullable', 'integer'],
            'thread' => ['nullable', 'integer'],
            'to' => ['required', 'string', 'max:1000'],
            'cc' => ['nullable', 'string', 'max:1000'],
            'bcc' => ['nullable', 'string', 'max:1000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:200000'],
            'quote' => ['nullable', 'in:reply,forward'], // цитату исходного письма приклеивает `Composer::create`
            'files' => ['nullable', 'array'],
            'files.*' => ['string'],
            'forward' => ['nullable', 'array'],
            'forward.*' => ['integer'],
            'offer' => ['nullable', 'integer'],
            'vehicle' => ['nullable', 'integer'],
            'invoice' => ['nullable', 'integer'],
            'report' => ['nullable', 'in:release'],
            'closing' => ['nullable', 'string', 'max:80'],
            'closing_replaces' => ['nullable', 'integer'],
            'closing_label' => ['nullable', 'string', 'max:60'],
            'closing_files' => ['nullable', 'array'],
            'closing_send' => ['nullable', 'string', 'in:both,act,invoice'],
            'closing_thread' => ['nullable', 'string', 'max:80'],
            'closing_files.*' => ['string'],
            'request_deals' => ['nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
            'pickup_letter' => ['nullable', 'string', 'regex:/^\d+:(?:date|act)$/'],
            'extra' => ['nullable', 'array', 'max:30'],
            'extra.*' => ['integer'],
            'extra_city' => ['nullable', 'string', 'max:80'],
        ], ['account.in' => 'С этого ящика писать нельзя'], ['to' => 'Кому', 'cc' => 'Копия', 'subject' => 'Тема', 'body' => 'Письмо']);
    }

    private function guard(Thread $thread): void
    {
        abort_unless($thread->account->scope === $this->scope || $this->across($thread), 404);
    }

    /**
     * Письмо — только из ящика своей стороны: повтор, флажок, разбор и вложения чужих ящиков — 404. Исключение — письма
     * той же машины с другой стороны (`Thread::ofCar`): их окно писем показывает, файлы и тело открываются.
     */
    private function guardMessage(Message $message): void
    {
        abort_unless($message->account?->scope === $this->scope || ($message->thread && $this->across($message->thread)), 404);
        // Управляющий с городом: письмо ветки другого города — 404 (ветки сужает область `Park\City`, письма — нет).
        abort_if($message->thread_id && City::viewing() && ! Thread::whereKey($message->thread_id)->exists(), 404);
    }

    /** Ветка другой стороны той же машины, и у человека есть почта той стороны. */
    private function across(Thread $thread): bool
    {
        $user = auth()->user();

        return $this->scope === Scope::Park
            ? $thread->offer_id && $user?->canCrmMail() && Vehicle::whereIn('car_id', Offer::whereKey($thread->offer_id)->select('car_id'))->exists()
            : $thread->vehicle_id && $user?->canManagePark() && Vehicle::whereKey($thread->vehicle_id)->whereHas('offer')->exists();
    }
}
