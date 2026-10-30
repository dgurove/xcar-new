<?php

namespace App\Http\Admin;

use App\Billing\ChargeKind;
use App\Billing\Documents\StorageActPdf;
use App\Billing\Invoice;
use App\Live\Stream;
use App\Mail\Account;
use App\Mail\Actions\ArchiveThread;
use App\Mail\Actions\FreezeMessages;
use App\Mail\Actions\LinkThread;
use App\Mail\Actions\MarkThreadRead;
use App\Mail\Actions\PromoteCandidate;
use App\Mail\Actions\StoreOutboxFile;
use App\Mail\Attachment;
use App\Mail\BodyRenderer;
use App\Mail\Boxes;
use App\Mail\Candidate;
use App\Mail\CandidateState;
use App\Mail\Chains\ChainBuilder;
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
use App\Mail\SendState;
use App\Mail\Sync;
use App\Mail\Template;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Media\PhotoIngest;
use App\Media\Thumb;
use App\Offers\Actions\AutoOffer;
use App\Offers\Actions\MarkSupplierRequestSent;
use App\Offers\Intake\OpenDraft;
use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Offers\Pricing;
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
use App\Park\Sale;
use App\Park\Vehicle;
use App\Park\VehicleState;
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
use App\Workflow\Requirement;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
    private const ONCE = ['unread', 'files', 'quiet'];

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
        $accounts = Account::where('scope', $this->scope)->orderBy('title')
            // Не админу в CRM — только ящики, что принимают (`Thread::crmMailFor`): deal@ ему не виден.
            ->when($this->scope === Scope::Offers && ! $request->user()->isAdmin(), fn ($a) => $a->whereNotNull('reply_account_id'))->get();
        // Открывается «Все» — почта прежде всего почта; «Требуют внимания» стоит первой пилюлей рядом.
        $box = $forced ?? (array_key_exists($request->query('box', ''), Boxes::BOXES) ? $request->query('box') : 'all');
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
            Facet::toggle('files', 'С файлами', fn ($t) => $t->where('has_attachments', true)),
            // «Прочее» (автоответы, бухгалтерия, письма без машины не от вендора) стоит во «Все»: убрать — этим чипом.
            Facet::toggle('quiet', 'Без прочего', fn ($t) => $t->where(fn ($w) => Boxes::notNoise($w))),
        )->countBy('count(distinct '.Boxes::group($this->scope).')');
        // Помнятся вендор, смысл и ящик. Переключатели — на этот раз: забытый «Непрочитанные» прятал у модератора все
        // прочитанные письма неделями (16.10.2026).
        ListPrefs::sync($request, $list, keep: [...array_diff($facets->keys(), self::ONCE), 'account'], view: false);
        $park = $this->scope === Scope::Park;
        // Ящик (13.10.2026, владелец: «письма только с offer и только с deal»): выбран — и дела, и письма в их карточках
        // только этого ящика. У парковки ящик один, в «Из писем» выборка вшита — переключателя нет.
        $mailbox = ! $park && ! $forced && $accounts->count() > 1 ? $accounts->firstWhere('slug', (string) $request->query('account')) : null;
        $boxAccounts = $mailbox ? collect([$mailbox]) : $accounts;
        $with = ['account', 'vendor', 'offer', 'offer.vendor', 'vehicle', 'vehicle.yard', 'vehicle.vendor', 'candidate.vendor', 'latestMessage.author', 'latestIncoming'];

        $all = Thread::query()->whereIn('account_id', $boxAccounts->pluck('id'))->where('messages_count', '>', 0)
            ->when($this->scope === Scope::Offers, fn ($t) => $t->crmMailFor($request->user()));
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
            ->with(['messages.attachments', 'messages.author'])->get()->groupBy($own) : collect();
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
        $counts = $forced ? [] : [
            'attention' => (int) $facets->applyTo($pick('attention'))->selectRaw('count(distinct '.$group.') as n')->value('n'),
        ];

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
            return redirect("/offers/{$candidate->offer->number}");
        }

        return $this->draft($candidate, $request->user());
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
     * `mail-draft.{id}`): внизу «Отменить» и «Не заявка» вместо «Опубликовать». Предложение с тем же убытком или VIN
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
            return redirect("/offers/{$offer->number}")->with('toast', 'Письма привязаны к предложению');
        }
        session()->put("mail-draft.{$offer->id}", $candidate->id);

        return redirect("/offers/{$offer->number}");
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
        $thread->load(['account', 'offer', 'vehicle', 'messages.account', 'messages.thread', 'messages.attachments', 'messages.addresses', 'messages.author']);
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

    public function show(Request $request, Thread $thread, MarkThreadRead $markRead, BodyRenderer $renderer)
    {
        $this->guard($thread);
        $thread->load(['account', 'offer', 'vehicle', 'messages.account', 'messages.thread', 'messages.attachments', 'messages.addresses', 'messages.author']);
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
        // Письмо пишется только в окне (28.10.2026, владелец: «зачем отдельной страницей, мы же делали в окне») — прямой заход
        // по адресу (старая ссылка, Telegram) открывает почту с этим окном. Первым делом: без ящика окно скажет само.
        if (! $request->header('Turbo-Frame')) {
            return $this->inWindow($request);
        }
        // Ящики, что только принимают (offer@), не пишут: с них не начинают и не отвечают.
        $accounts = Account::where('scope', $this->scope)->sending()->orderBy('title')->get();
        $account = $accounts->firstWhere('slug', $request->query('account')) ?? $accounts->first();
        abort_unless($account, 404);
        $template = $request->query('template') ? Template::where('scope', $this->scope)->find($request->query('template')) : null;
        // Предложение — письмо CRM, машина и счёт — письма парковки: чужая сторона их не подставляет.
        $sale = $this->scope === Scope::Offers;
        $offer = $sale && $request->query('offer') ? OfferNumber::find((string) $request->query('offer')) : null;
        $vehicle = ! $sale && $request->query('car') ? Vehicle::find($request->query('car')) : null;
        // Счёт за хранение вендору: ТС счёта, шаблон «Счёт за хранение», PDF счёта и акта, адресат — бухгалтерия вендора.
        // Счёт в письме — деньги: приёмщику парковки (`User::parkMoney`) не подставляется (27.10.2026).
        abort_if(! $sale && $request->query('invoice') && ! $request->user()->parkMoney(), 404);
        $invoice = ! $sale && $request->query('invoice') ? Invoice::with(['vehicle', 'party'])->find($request->query('invoice')) : null;
        if ($invoice?->vehicle) {
            $vehicle = $invoice->vehicle;
            $template ??= Template::park('invoice');
        }
        // Счёт-реестр за месяц — новое письмо в бухгалтерию плательщика (вендора, иначе адрес контрагента), без ветки ТС.
        $registryValues = [];
        if ($invoice?->isRegistry()) {
            $template ??= Template::park('registry');
            $payer = Vendor::with('contacts')->where('party_id', $invoice->party_id)->first();
            $registryValues = ['month' => mb_strtolower($invoice->month->translatedFormat('F Y')), 'count' => (string) $invoice->registryVehicles()->count(),
                'invoice' => $invoice->label(), 'sum' => Money::rub($invoice->total),
                'to' => $payer?->email(ContactRole::Accounting, ContactRole::Storage, ContactRole::Claims) ?? $invoice->party->email ?? ''];
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
        if ($vehicle) {
            // Письмо о машине отвечает в ту ветку, которой приехала заявка.
            // Ветка письма покупателю (пропуск по QR) — не переписка с вендором.
            $thread = Thread::where('vehicle_id', $vehicle->id)->park()->where('buyer', false)->orderByDesc('last_message_at')->first();
            $parent = $thread?->messages()->where('direction', Direction::In)->orderByDesc('date_at')->first();
            $vehicle->loadMissing('vendor.contacts');
            $values = self::vehiclePlaceholders($vehicle) + ['to' => $invoice
                ? ($vehicle->vendor?->email(ContactRole::Accounting, ContactRole::Storage, ContactRole::Claims) ?? $parent?->replyToAddress() ?? '')
                : ($parent?->replyToAddress() ?? $vehicle->vendor?->email(ContactRole::Storage, ContactRole::Claims) ?? '')];
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
        $defaults = $offer && $parent ? $composer->replyWith($parent, $account, $template, $values) : $composer->fresh($account, $template, $values);
        if ($saleTo ?? null) {
            $defaults['to'] = $saleTo;
        }
        // Адрес из копии вендора, ставший адресатом (общий total@ Т-Страхования, когда писем по сделке нет), в копии не повторяем.
        $to = array_map(fn ($a) => mb_strtolower(trim($a)), explode(',', (string) ($defaults['to'] ?? '')));
        $defaults['cc'] = implode(', ', array_filter(array_map('trim', explode(',', (string) ($defaults['cc'] ?? ''))), fn ($a) => $a !== '' && ! in_array(mb_strtolower($a), $to, true)));
        if ($parent && ! $offer) {
            $defaults['subject'] = $defaults['subject'] ?: 'Re: '.$parent->subject;
        }
        // Письмо о выдаче уходит с актом и фото выдачи; лишнее снимают в форме. `act=release` — акт выдачи (в том числе с
        // отказом от получения, когда ТС осталась).
        if ($invoice && ! $request->old()) {
            foreach (['file', 'act'] as $collection) {
                if ($media = $invoice->getFirstMedia($collection)) {
                    $defaults['files'][] = $outbox->put($media->file_name, file_get_contents($media->getPath()));
                }
            }
            if (! $invoice->getFirstMedia('act') && $invoice->kind === ChargeKind::Storage) {
                $defaults['files'][] = $outbox->put('akt-hraneniya-'.$invoice->number.'-'.$invoice->year.'.pdf', $actPdf->render($invoice));
            }
        } elseif ($vehicle?->accepted_at && $request->query('act') === 'release' && ! $request->old()) {
            // Отчёт о приёме — своей формой (`x-park.report-form`, 12.10.2026: наш акт и 12 фото никто не слал); здесь — выдача.
            $intake = false;
            $defaults['files'][] = $outbox->put($act->filename($vehicle, $intake), $act->render($vehicle, $intake));
            $stage = $intake ? PhotoStage::Intake : PhotoStage::Release;
            // Сначала снятое на парковке, потом приехавшее из писем: вендору нужны наши кадры, а не его же.
            // Файлы из писем у выданной стирает фоновая очистка (`PurgeReleasedLetters`) — в письмо их не берём: файл мог
            // исчезнуть посреди сборки, а вендору и так нужны наши кадры.
            $doomed = $vehicle->state === VehicleState::Released ? PurgeLetters::media($vehicle)->pluck('id')->all() : [];
            $shots = $vehicle->photos()->filter(fn ($m) => PhotoStage::of($m) === $stage && ! in_array($m->id, $doomed, true))
                ->sortBy(fn ($m) => $m->getCustomProperty('source') === 'mail' ? 1 : 0)->values();
            $pool = ($shots->isNotEmpty() ? $shots : $vehicle->visiblePhotos()->reject(fn ($m) => in_array($m->id, $doomed, true)))
                ->filter(fn ($m) => is_file($m->getPath()))->values();
            foreach ($pool->take(12) as $i => $m) {
                $contents = file_get_contents($m->getPath());
                // Во вложение уходит ужатый кадр, а `sha` у него от исходника: запоминаем отпечаток отправленного,
                // иначе это же письмо из «Отправленных» принесёт наши фото обратно вторым экземпляром.
                if ($m->getCustomProperty('sent_sha') !== ($sha = hash('sha256', $contents))) {
                    $m->setCustomProperty('sent_sha', $sha)->save();
                }
                $defaults['files'][] = $outbox->put(($intake ? 'priem' : 'vydacha').'-'.($i + 1).'.'.pathinfo($m->file_name, PATHINFO_EXTENSION), $contents);
            }
        }

        // Письмо поставщику об оплате (шаблон этапа, Альфа): платёжка, что менеджер приложил на шаге оплаты, — во вложении
        // (18.10.2026: «Платёжное поручение во вложении» уходило без него).
        if ($offer?->deal && $template && ! $request->old() && $template->id === $offer->stage()?->template_id) {
            $slip = Requirement::where('deal_id', $offer->deal->id)->whereNotNull('done_at')
                ->where('answer->exit', 'Платёжное поручение приложено')->latest('done_at')->first();
            foreach ($slip?->getMedia('files') ?? [] as $media) {
                $defaults['files'][] = $outbox->put($media->file_name, file_get_contents($media->getPath()));
            }
        }

        if ($supplierRequest && ! $request->old()) {
            $defaults['files'][] = $outbox->put($supplierRequest->filename(), $supplierRequest->xlsx());
        }

        return view('admin.mail.compose-frame', ['frame' => $request->header('Turbo-Frame'), 'account' => $account, 'accounts' => $accounts, 'thread' => $thread, 'parent' => $parent, 'defaults' => $defaults, 'invoice' => $invoice,
            'mode' => 'new', 'templates' => Template::where('scope', $this->scope)->orderBy('name')->get(), 'offer' => $offer, 'vehicle' => $vehicle, 'base' => $this->base,
            // Черновик после «Принять» (BidController::accepted): отправили — окно закрывается, человек идёт дальше по списку.
            'then' => $request->query('then') === 'close' ? 'close' : null,
            'requestDeals' => $supplierRequest?->deals->pluck('id')->implode(',')]);
    }

    /**
     * Редактор без окна (старая ссылка со страницы, открытой до выкладки; Telegram) — окно писем на той же странице, откуда
     * пришли (28.10.2026, владелец: «перебрасывает в раздел почта, бесит»): назад с `session('letters')`, его берёт
     * `x-mail.window` любой страницы. Пришли не со своей страницы — почта с этим окном (`?compose=`).
     */
    private function inWindow(Request $request)
    {
        $uri = $request->getRequestUri();
        $from = (string) $request->headers->get('referer');
        $same = $from !== '' && parse_url($from, PHP_URL_HOST) === $request->getHost() && ! str_contains($from, '/mail/new') && ! str_contains($from, '/reply/');

        return $same ? redirect($from)->with('letters', $uri) : redirect($this->base.'?compose='.urlencode($uri));
    }

    public function reply(Request $request, Thread $thread, Message $message, Composer $composer)
    {
        $this->guard($thread);
        abort_unless($message->thread_id === $thread->id, 404);
        // «Отмена» в ленте: вместо редактора снова кнопка в том же фрейме.
        if ($request->boolean('cancel') && str_starts_with((string) $request->header('Turbo-Frame'), 'reply')) {
            return view('admin.mail.reply-frame', ['message' => $message, 'base' => $this->base, 'frame' => $request->header('Turbo-Frame')]);
        }
        $message->load(['account.replyAccount', 'addresses', 'attachments']);
        $mode = $request->query('mode', 'reply');
        $defaults = $mode === 'forward' ? $composer->forward($message) : $composer->reply($message, $mode === 'all');
        // Ящик, что только принимает (offer@), отвечает через свой «Отвечаем с ящика» (deal@).
        $sender = $message->account->sender();

        // «От» — любой ящик своей стороны, что пишет (28.10.2026, владелец: «от я бы добавил возможность выбирать»); по
        // умолчанию — тот, что отвечает за ящик письма.
        $accounts = Account::where('scope', $this->scope)->sending()->orderBy('title')->get();

        if (! $request->header('Turbo-Frame')) {
            return $this->inWindow($request);
        }

        return view('admin.mail.compose-frame', ['frame' => $request->header('Turbo-Frame'), 'account' => $sender, 'accounts' => $accounts->contains('id', $sender->id) ? $accounts : collect([$sender]), 'thread' => $thread, 'parent' => $message,
            'defaults' => $defaults, 'mode' => $mode, 'templates' => collect(), 'offer' => $thread->offer, 'vehicle' => $thread->vehicle, 'base' => $this->base]);
    }

    public function send(Request $request, Composer $composer, MarkDoc $mark, MarkSupplierRequestSent $sentRequest)
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

        // Родитель — письмо своей стороны: ответ можно отправить с любого её ящика («От»), с чужого ящика он уйдёт новой
        // веткой с In-Reply-To (`Composer::create`).
        $boxes = Account::where('scope', $this->scope)->pluck('id');
        $parent = ! empty($data['parent']) ? Message::with(['thread', 'attachments', 'account'])->whereIn('account_id', $boxes)->find($data['parent']) : null;
        $thread = ! empty($data['thread']) ? Thread::where('account_id', $account->id)->find($data['thread']) : null;

        // Файл, которого нет в outbox, письмо не заводит (`Composer::attach`, 29.10.2026: «Письмо пришло без вложения»), а
        // «Отправить», пока файлы грузятся, редактор не пускает (`photos_controller`) — возвращаемся в редактор с ошибкой.
        try {
            $message = $composer->create($account, $data, $parent, $request->user(), $thread);
        } catch (ValidationException $e) {
            if ($retry) {
                return redirect($retry)->withErrors($e->errors())->withInput();
            }
            throw $e;
        }
        if ($this->scope === Scope::Offers && ! empty($data['offer']) && $message->thread && ! $message->thread->offer_id) {
            $message->thread->update(['offer_id' => $data['offer']]);
        }
        if ($this->scope === Scope::Park && ! empty($data['vehicle']) && $message->thread && ! $message->thread->vehicle_id) {
            $message->thread->update(['vehicle_id' => $data['vehicle']]);
        }
        // Ушёл акт и фото — бумаги «акты» и «фото» вендору отмечаются отправленными этим письмом, в ленте ТС — отчёт.
        if ($this->scope === Scope::Park && ! empty($data['vehicle']) && ($vehicle = Vehicle::find($data['vehicle']))) {
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
            if (! empty($data['invoice']) && $request->user()->parkMoney() && ($invoice = Invoice::where('vehicle_id', $vehicle->id)->find($data['invoice']))) {
                $invoice->update(['sent_at' => now()]);
                $vehicle->log(EventType::ReportSent, $request->user(), ['what' => 'Счёт '.$invoice->label(), 'thread' => $message->thread_id]);
            }
        }
        // Ушёл счёт-реестр — отметка на нём и отчёт в ленте каждой его ТС.
        if ($this->scope === Scope::Park && ! empty($data['invoice']) && $request->user()->parkMoney() && ($registry = Invoice::whereNull('vehicle_id')->whereNotNull('month')->find($data['invoice']))) {
            $registry->update(['sent_at' => now()]);
            foreach ($registry->registryVehicles() as $v) {
                $v->log(EventType::ReportSent, $request->user(), ['what' => 'Реестр '.$registry->label(), 'thread' => $message->thread_id]);
            }
        }

        // Заявка поставщику ушла — сделки из её файла идут дальше «Заявка отправлена».
        $requested = $this->scope === Scope::Offers && ! empty($data['request_deals'])
            ? $sentRequest(array_map('intval', explode(',', $data['request_deals'])), $message, $request->user()) : null;

        if ($inWindow) {
            // Письмо вендору после «Принять» — окно закрывается само (поток letters без адреса в admin/mail/window).
            if ($request->input('then') === 'close') {
                return redirect("{$this->base}/{$message->thread_id}/window")->with('toast', $requested !== null ? "Заявка поставщику в очереди, {$requested} ТС" : 'Письмо вендору в очереди')->with('letters-close', true);
            }

            return redirect("{$this->base}/{$message->thread_id}/window")->with('toast', 'Письмо в очереди');
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

    /** Исходное письмо целиком (фрейм body-{id} в ленте): тело в песочнице iframe, ?images=1 — с картинками из сети. */
    public function body(Request $request, Message $message, BodyRenderer $renderer)
    {
        $this->guard($message->thread);

        return view('admin.mail.body-frame', ['message' => $message, 'base' => $this->base, 'renderer' => $renderer,
            'document' => $renderer->document($message, $request->boolean('images'), $this->base), 'images' => $request->boolean('images')]);
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
            'floor' => Pricing::floorLabel($offer->floor_price, $offer->value),
            // Письмо поставщику — сколько получает он: наша закупка (`supplierCost`) или то, что покупатель платит ему сам
            // («Страховой напрямую»), а не цена менеджера (18.10.2026: «оплачен на сумму» уходило Альфе с ценой сделки).
            'price' => ($p = $offer->deal ? ($offer->deal->paysSelection() ? $offer->deal->supplierPayment() : $offer->deal->supplierCost()) : null) ? Money::plain($p) : ($offer->asking_price ? Money::plain($offer->asking_price) : ''),
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

    /** Абзац про выдачу по QR — перед «С уважением», а без него — в конец. */
    private static function withPickupNotice(string $body): string
    {
        $at = mb_strpos($body, 'С уважением');

        return $at === false ? rtrim($body)."\n\n".Template::PICKUP_NOTICE : rtrim(mb_substr($body, 0, $at))."\n\n".Template::PICKUP_NOTICE."\n\n".mb_substr($body, $at);
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
    private static function vendorLetter(Offer $offer): ?Message
    {
        $threads = Thread::where('offer_id', $offer->id)->whereIn('account_id', Account::where('scope', Scope::Offers)->select('id'))->pluck('id');

        if ($threads->isEmpty()) {
            return null;
        }
        $letters = Message::whereIn('thread_id', $threads)->with(['account.replyAccount', 'addresses', 'thread'])->orderByDesc('date_at')->orderByDesc('id')->get();

        // Его последнее письмо, а пока он не писал — наше последнее ему (28.10.2026): «Подтверждаем актуальность» ответом
        // на «Выражаем интерес», одной веткой «Re: …», а не новым письмом, которое у страховой потеряется.
        return $letters->first(fn (Message $m) => $m->direction === Direction::In && $m->isFromVendor())
            ?? $letters->first(fn (Message $m) => $m->direction === Direction::Out && $m->message_id);
    }

    private function sendRules(Request $request): array
    {
        return $request->validate([
            // Ящик — только своей стороны: с парковки не уйдёт письмо от offer@ или deal@.
            'account' => ['required', Rule::exists('mail_accounts', 'slug')->where('scope', $this->scope->value)],
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
            'request_deals' => ['nullable', 'string', 'regex:/^\d+(,\d+)*$/'],
        ], [], ['to' => 'Кому', 'cc' => 'Копия', 'subject' => 'Тема', 'body' => 'Письмо']);
    }

    private function guard(Thread $thread): void
    {
        abort_unless($thread->account->scope === $this->scope || $this->across($thread), 404);
        self::guardSales($thread);
    }

    /** Ветка продаж, закрытая не админу (`Thread::crmMailFor`): deal@ и предложения, что были в сделке, — 404. */
    private static function guardSales(?Thread $thread): void
    {
        $user = auth()->user();
        abort_if($thread && $user && ! $user->isAdmin() && $thread->account?->scope === Scope::Offers
            && ! Thread::whereKey($thread->id)->crmMailFor($user)->exists(), 404);
    }

    /**
     * Письмо — только из ящика своей стороны: повтор, флажок, разбор и вложения чужих ящиков — 404. Исключение — письма
     * той же машины с другой стороны (`Thread::ofCar`): их окно писем показывает, файлы и тело открываются.
     */
    private function guardMessage(Message $message): void
    {
        abort_unless($message->account?->scope === $this->scope || ($message->thread && $this->across($message->thread)), 404);
        self::guardSales($message->thread);
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
