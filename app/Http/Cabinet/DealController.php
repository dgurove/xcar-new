<?php

namespace App\Http\Cabinet;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Live\RefreshDeal;
use App\Live\Stream;
use App\Logistics\Actions\RequestPickup;
use App\Logistics\Actions\SelfPickup;
use App\Media\PhotoIngest;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Handover;
use App\Offers\InsurerReplies;
use App\Offers\OfferState;
use App\Offers\ReplyText;
use App\Support\ListPrefs;
use App\Support\Sort;
use App\Users\User;
use App\Workflow\Actions\AnswerRequirement;
use App\Workflow\Outcome;
use App\Workflow\Path;
use App\Workflow\Requirement;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DealController
{
    /** «В работе»: где ход менеджера — первыми (просроченное, потом по сроку), дальше дата или сумма — выбором. */
    public const SORTS = ['fresh' => ['Дата', 'desc'], 'amount' => ['Сумма', 'desc']];

    /** Архив — сколько строк каждого раздела показываем; сделки целиком ведёт CRM, тут — история менеджера. */
    private const ARCHIVE = 100;

    /**
     * Сделки — только сделки в работе (11.10.2026, владелец). Подтверждения — не сделки: «менеджеры подтверждают кучу
     * машин, а берём редко» — они в «Моих подтверждениях» (`?view=bids`), закончившееся и не состоявшееся — в архиве
     * (`?view=archive`); входы — строками в конце списка.
     */
    public function index(Request $request)
    {
        $me = $request->user();
        abort_unless($me->hasDeals() || $me->isAdmin(), 404);
        if ($request->query('view') === 'archive') {
            return $this->archive($request);
        }
        if ($request->query('view') === 'bids' && $me->hasDeals()) {
            return $this->bidsScreen($request);
        }
        ListPrefs::sync($request, 'deals', view: false, keep: ['sort']);
        $sort = Sort::from($request->query('sort'), self::SORTS, '-fresh');
        // Гаражные сделки живут в «Гараже» (решение владельца 03.10.2026), здесь — только ждущее решения «В гараж».
        // Ход менеджера — просьба к нему или этап, где ждут его (ПРАЙМ без покупателя — без просьбы): просроченное, потом остальное.
        $turn = Deal::MANAGER_TURN_SQL;
        $deals = self::deals($me)->where('state', DealState::Active)
            ->orderByRaw("case when {$turn} then 0 else 1 end")
            ->orderByRaw('(select min(r.due_at) from requirements r where r.deal_id = deals.id and r.done_at is null) asc nulls last')
            ->when($sort->key === 'amount', fn ($d) => $d->orderByRaw("amount {$sort->dir()} nulls last"))->orderBy('created_at', $sort->key === 'fresh' ? $sort->dir() : 'desc')->orderByDesc('id');
        // Админу — все сделки площадки строками, постранично; менеджеру — свои разделами «чей ход» одной страницей.
        $staff = $me->isAdmin() && ! $me->isManager();
        $deals = $staff ? $deals->paginate(30) : $deals->get();
        $sections = $staff ? null : self::sections($deals);

        // Админ подтверждения решает в CRM — строки «Мои подтверждения» у него нет.
        $bids = $me->hasDeals() ? self::waiting($me)->count() : 0;
        $archived = self::deals($me)->where('state', '!=', DealState::Active)->notSuperseded()->count();

        return view('cabinet.deals.index', ['deals' => $deals, 'sections' => $sections, 'bids' => $bids, 'archived' => $archived, 'sort' => $sort]);
    }

    /**
     * Разделы «Сделок» менеджера (27.10.2026, владелец: свои дела терялись рыжей строкой среди «Ждём поставщика»): «Ваш ход» —
     * что сделать (`Deal::managerTask`), просроченное и ближний срок первыми; дальше — чей ход сейчас. Пустые не рисуются.
     *
     * @return array<string, array{title: string, deals: \Illuminate\Support\Collection}>
     */
    private static function sections($deals): array
    {
        $tasks = $deals->mapWithKeys(fn (Deal $d) => [$d->id => $d->managerTask()]);
        [$mine, $rest] = $deals->partition(fn (Deal $d) => $tasks[$d->id] !== null);
        $mine = $mine->sortBy(fn (Deal $d) => $tasks[$d->id]['due']?->getTimestamp() ?? PHP_INT_MAX)->values();
        $who = fn (Deal $d) => match ($d->offer->position()?->waitsFor()) {
            WaitsFor::Supplier => 'supplier',
            WaitsFor::Us => 'us',
            default => 'other',
        };
        $rest = $rest->groupBy($who);

        return array_filter([
            'mine' => ['title' => 'Ваш ход', 'deals' => $mine],
            'supplier' => ['title' => 'Ждём поставщика', 'deals' => $rest->get('supplier', collect())],
            'us' => ['title' => 'Ждём XCar', 'deals' => $rest->get('us', collect())],
            'other' => ['title' => 'Идёт сделка', 'deals' => $rest->get('other', collect())],
        ], fn ($s) => $s['deals']->isNotEmpty());
    }

    /**
     * Мои подтверждения (11.10.2026, владелец): «Ждут решения» — всё живое. Отданное другому — тоже здесь, тем же видом
     * (15.10.2026, владелец: второй не должен знать, что машину отдали, он резерв на случай, если первый сорвётся).
     * Отклонённых и отданных другому, чья продажа состоялась, тут нет вовсе.
     */
    private function bidsScreen(Request $request)
    {
        return view('cabinet.deals.bids', ['waiting' => self::waiting($request->user())->get()]);
    }

    /** Архив: закончившиеся и отменённые сделки. Подтверждений здесь нет — они не сделки. */
    private function archive(Request $request)
    {
        $me = $request->user();
        $closed = self::deals($me)->where('state', '!=', DealState::Active)->notSuperseded()
            ->orderByRaw('coalesce(closed_at, updated_at) desc')->orderByDesc('id')->limit(self::ARCHIVE)->get();

        return view('cabinet.deals.archive', ['done' => $closed->where('state', DealState::Done)->values(), 'failed' => $closed->where('state', DealState::Cancelled)->values()]);
    }

    /** Сделки экрана: без гаражных, с тем, что нужно строке. */
    private static function deals(User $me)
    {
        return Deal::with(['offer', 'offer.media', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'openRequirement'])
            ->when(! $me->hasDeals(), fn ($d) => $d->with('buyer'))->of($me)->whereNull('garage_payer');
    }

    private static function bids(User $me)
    {
        return Bid::with(['offer', 'offer.media'])->where('user_id', $me->id)->latest();
    }

    /**
     * Ждут решения: предложение открыто без сделки — выбор впереди; или идёт чужая сделка — подтверждение в резерве, но
     * менеджеру это не говорится (15.10.2026).
     */
    private static function waiting(User $me)
    {
        return self::bids($me)->where('state', BidState::Active)->where(fn ($w) => $w
            ->whereHas('offer', fn ($o) => $o->where('state', OfferState::Open)->whereDoesntHave('deal'))
            ->orWhereHas('offer.deal', fn ($d) => $d->where(fn ($b) => $b->whereNull('buyer_id')->orWhere('buyer_id', '!=', $me->id))));
    }

    public function show(Request $request, Deal $deal)
    {
        $me = $request->user();
        // Админ ведёт сделку в CRM: страница сделки на сайте — работа менеджера («Ваша доля», «Оплатить»).
        if ($me->isAdmin() && $deal->buyer_id !== $me->id) {
            return redirect()->away($deal->crmUrl());
        }
        abort_unless($deal->buyer_id === $me->id, 404);
        // Переоформили (отменили и приняли снова) — старая ссылка ленты и Telegram ведёт на новую, без «Сделка отменена».
        if ($next = $deal->successor()) {
            return redirect($next->href());
        }
        // Отменённая сделка машину не открывает (13.10.2026): кадры, VIN и документы — только пока она ему видна.
        if ($deal->state === DealState::Cancelled && ! $deal->offer->isVisibleTo($me)) {
            return redirect('/deals?view=archive')->with('toast', 'Сделка отменена');
        }
        // Гаражная сделка живёт в гараже: старые ссылки ленты и Telegram ведут туда же.
        if ($deal->isGarage() && $deal->garageCar()->exists()) {
            return redirect($deal->href());
        }

        return view('cabinet.deals.show', self::stepData($deal));
    }

    /** Карточка чата по сделке — фрейм перечитывает её по новому сообщению. */
    public function chat(Request $request, Deal $deal)
    {
        abort_unless($deal->buyer_id === $request->user()->id && $deal->offer->chatOpenFor($request->user()), 404);

        return view('cabinet.deals.chat-card', ['deal' => $deal, 'offer' => $deal->offer]);
    }

    /** Сделка с текущим шагом, путём и счетами — для её страницы и карточки машины в гараже. */
    public static function stepData(Deal $deal): array
    {
        $deal->load(['offer', 'offer.media', 'offer.settlement', 'offer.positions.stage.block', 'offer.positions.stage.workflow', 'offer.positions.stage.exits.to', 'openRequirement.media', 'requirements']);
        $position = $deal->offer->position(Track::Sale);
        // Журнал с начала сделки, с учётом откатов — тот же, что путь в CRM.
        $steps = Path::journal($deal->offer, Track::Sale, $deal->created_at);
        // Менеджеру путь целиком: на развилке — главной дорогой (`guess`), а не обрывом на текущем шаге.
        $blocks = $position ? Path::ladder($position->stage, $steps->pluck('block')->all(), $deal, guess: true) : collect();

        return [
            'deal' => $deal,
            'offer' => $deal->offer,
            'position' => $position,
            'blocks' => $blocks,
            'steps' => $steps,
            'requirement' => $deal->openRequirement,
            'exits' => $deal->openRequirement ? Handover::managerExits($position?->stage, $deal) : collect(),
            'handover' => Handover::for($deal),
            // Ответы страховой по сделке — урезанные (`ReplyText`, 15.10.2026: без подписи, вендора, сумм), новые сверху;
            // файлов писем менеджеру нет. Пустой после урезания ответ не показывается. До согласования — никаких (27.10.2026).
            'replies' => ! $deal->agreed() ? collect() : InsurerReplies::for($deal)->filter(fn ($m) => ReplyText::for($m, $deal->offer) !== null)->values(),
            'invoices' => Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('state', '!=', InvoiceState::Void)
                // Шторка счёта (`cabinet.deals.invoice-sheet`): строки, оплаты с платёжками, ссылка — сразу, не по запросу на счёт.
                ->with(['claims', 'party', 'charges', 'allPayments.media', 'payLinks.attempts', 'media'])->orderBy('id')->get()
                ->each(fn (Invoice $i) => $i->setRelation('deal', $deal)),
        ];
    }

    public function answer(Request $request, Deal $deal, AnswerRequirement $answer)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $requirement = $deal->openRequirement()->firstOrFail();
        $exit = Outcome::findOrFail($request->validate(['exit' => ['required', 'integer']])['exit']);
        $answer($requirement, $exit, $request->user(), (array) $request->input('fields', []));

        // Сразу туда, где сделку смотрят (гаражная — в гараж): второй редирект съел бы тост.
        return redirect($deal->refresh()->href())->with('toast', $exit->label);
    }

    /** Кто заберёт машину с нашей парковки и когда (`SetReceiver`): пропуск новый, парковка видит сразу. */
    public function receiver(Request $request, Deal $deal, \App\Offers\Actions\SetReceiver $set)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $deal = $set($deal, $request->user(), $request->validate(['who' => ['required', 'string', 'max:20'], 'name' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'], 'at' => ['nullable', 'date']]));

        return redirect($deal->href())->with('toast', 'Заберёт '.$deal->receiver()?->labelFor($request->user()));
    }

    /** «Нужен вывоз» — менеджер, что забирает сам, просит логистов (`RequestPickup`). */
    public function requestPickup(Request $request, Deal $deal, RequestPickup $pickup)
    {
        abort_unless($deal->buyer_id === $request->user()->id && $deal->isActive() && $deal->offer, 404);
        $pickup($deal->offer, $request->user());

        return redirect($deal->href())->with('toast', 'Попросили вывоз, логистика назначит исполнителя');
    }

    /** «Автомобиль забрал», когда шага получения в маршруте нет (`Handover::canPick`): вывоз закрыт у него. */
    public function picked(Request $request, Deal $deal, SelfPickup $self)
    {
        abort_unless($deal->buyer_id === $request->user()->id && $deal->isActive() && $deal->offer, 404);
        abort_unless(Handover::for($deal)->canPick, 422, 'Забрать сейчас нельзя');
        $self($deal->offer, $request->user(), $request->user());

        return redirect($deal->href())->with('toast', 'Автомобиль забрал');
    }

    public function upload(Request $request, Deal $deal, PhotoIngest $ingest)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $requirement = $deal->openRequirement()->firstOrFail();
        $request->validate(['file' => ['required', 'file', 'max:30720']]);
        $file = $request->file('file');
        try {
            if (str_starts_with((string) $file->getMimeType(), 'image/')) {
                $ingest->fromUpload($requirement, 'files', $file);
            } else {
                $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $requirement->addMedia($file)->usingFileName((trim(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $name), '-.') ?: 'dokument').'.'.strtolower($file->getClientOriginalExtension()))->toMediaCollection('files');
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $this->filesStream($deal, $requirement);
    }

    public function removeFile(Request $request, Deal $deal, Media $media)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $requirement = $deal->openRequirement()->firstOrFail();
        abort_unless($media->model_id === $requirement->id && $media->model_type === $requirement::class, 404);
        $media->delete();

        return $this->filesStream($deal, $requirement);
    }

    /** Файлы шага, его кнопки (появляются с первым файлом) и галка «Подписанный договор» — одним потоком. */
    private function filesStream(Deal $deal, Requirement $requirement)
    {
        // Файл шага видят CRM (путь сделки) и вторая вкладка менеджера — без перезагрузки (19.10.2026).
        RefreshDeal::of($deal);
        $requirement = $requirement->fresh();
        $stage = $deal->offer->position(Track::Sale)?->stage;

        return Stream::view('cabinet.deals.files-stream', ['requirement' => $requirement, 'deal' => $deal,
            'exits' => Handover::managerExits($stage, $deal)]);
    }
}
