<?php

namespace App\Http\Cabinet;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Live\RefreshDeal;
use App\Live\Stream;
use App\Logistics\Actions\RequestPickup;
use App\Logistics\Actions\SelfPickup;
use App\Offers\Actions\SetReceiver;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Disclosures;
use App\Offers\Handover;
use App\Offers\OfferState;
use App\Offers\Parallels;
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
use Illuminate\Support\Collection;
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
        // Гаражные — тоже сделки (02.11.2026, владелец: «гаражные тоже в сделках»; до того жили только в «Гараже»): в тех же
        // разделах, с меткой «Гараж», строка ведёт в машину гаража — там и шаг сделки.
        // Ход менеджера — просьба к нему или этап, где ждут его (ПРАЙМ без покупателя — без просьбы): просроченное, потом остальное.
        $turn = Deal::MANAGER_TURN_SQL;
        // Параллельная (07.11.2026) — его сделка, как идущая: со своим теневым шагом (`Parallels::dress`).
        $deals = self::deals($me)->whereIn('state', [DealState::Active, DealState::Parallel])
            ->orderByRaw("case when {$turn} then 0 else 1 end")
            ->orderByRaw('(select min(r.due_at) from requirements r where r.deal_id = deals.id and r.done_at is null) asc nulls last')
            ->when($sort->key === 'amount', fn ($d) => $d->orderByRaw("coalesce(amount, cost) {$sort->dir()} nulls last"))->orderBy('created_at', $sort->key === 'fresh' ? $sort->dir() : 'desc')->orderByDesc('id');
        // Админу — все сделки площадки строками, постранично; менеджеру — свои разделами «чей ход» одной страницей.
        $staff = $me->isAdmin() && ! $me->isManager();
        $deals = $staff ? $deals->paginate(30) : $deals->get()->each(fn (Deal $d) => Parallels::dress($d->loadMissing('shadowStage')));
        $sections = $staff ? null : self::sections($deals);

        // Админ подтверждения решает в CRM — строки «Мои подтверждения» и «Ждут решения» у него нет.
        $bids = $me->hasDeals() ? self::waiting($me)->count() : 0;
        $pending = $me->hasDeals() ? self::pending($me)->get()->sortByDesc(fn (Bid $b) => $b->offer->bids_close_at?->getTimestamp())->values() : collect();
        $archived = self::deals($me)->whereNotIn('state', [DealState::Active, DealState::Parallel])->notSuperseded()->count();

        return view('cabinet.deals.index', ['deals' => $deals, 'sections' => $sections, 'pending' => $pending, 'bids' => $bids, 'archived' => $archived, 'sort' => $sort]);
    }

    /**
     * Разделы «Сделок» менеджера (27.10.2026, владелец: свои дела терялись рыжей строкой среди «Ждём поставщика»): «Ваш ход» —
     * что сделать (`Deal::managerTask`), просроченное и ближний срок первыми; дальше — чей ход сейчас. Пустые не рисуются.
     *
     * @return array<string, array{title: string, deals: Collection}>
     */
    private static function sections($deals): array
    {
        $tasks = $deals->mapWithKeys(fn (Deal $d) => [$d->id => $d->managerTask()]);
        [$mine, $rest] = $deals->partition(fn (Deal $d) => $tasks[$d->id] !== null);
        $mine = $mine->sortBy(fn (Deal $d) => $tasks[$d->id]['due']?->getTimestamp() ?? PHP_INT_MAX)->values();
        // Гаражная, где ход его без просьбы, — продаёт из гаража: своим разделом, а не «Идёт сделка».
        // Ответ поставщика менеджеру не виден и тут: сделка остаётся в «Ждём поставщика» (`waitsForManager`, 03.11.2026).
        $who = fn (Deal $d) => match ($d->offer->position()?->waitsForManager()) {
            WaitsFor::Supplier => 'supplier',
            WaitsFor::Us => 'us',
            WaitsFor::Manager => $d->isGarage() ? 'garage' : 'other',
            default => 'other',
        };
        $rest = $rest->groupBy($who);

        return array_filter([
            'mine' => ['title' => 'Ваш ход', 'deals' => $mine],
            'supplier' => ['title' => 'Ждём поставщика', 'deals' => $rest->get('supplier', collect())],
            'us' => ['title' => 'Ждём XCar', 'deals' => $rest->get('us', collect())],
            'other' => ['title' => 'Идёт сделка', 'deals' => $rest->get('other', collect())],
            'garage' => ['title' => 'В гараже', 'deals' => $rest->get('garage', collect())],
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
        $closed = self::deals($me)->whereNotIn('state', [DealState::Active, DealState::Parallel])->notSuperseded()
            ->orderByRaw('coalesce(closed_at, updated_at) desc')->orderByDesc('id')->limit(self::ARCHIVE)->get();

        // Закрытая параллельная — без шага машины (`Parallels::dress`).
        $closed->each(fn (Deal $d) => Parallels::dress($d));

        return view('cabinet.deals.archive', ['done' => $closed->where('state', DealState::Done)->values(), 'failed' => $closed->where('state', DealState::Cancelled)->values()]);
    }

    /** Сделки экрана с тем, что нужно строке; гаражные тоже (02.11.2026) — машина гаража нужна ссылке строки. */
    private static function deals(User $me)
    {
        return Deal::with(['offer', 'offer.media', 'offer.positions.stage.block', 'offer.positions.stage.exits.to', 'openRequirement', 'garageCar.offer'])
            ->when(! $me->hasDeals(), fn ($d) => $d->with('buyer'))->of($me);
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

    /**
     * «Ждут решения» в «Сделках» (02.11.2026, владелец: «из подтверждённых пропало», 33 машины у 14 менеджеров): срок приёма
     * вышел — машина уходит из «Предложений» (`Offer::onSale`) и у подтвердившего стоит здесь, пока выбор за нами; отдали
     * другому — тут же тем же видом (резерв, 15.10.2026). Пропадает при отказе, завершённой сделке и архиве — подтверждение
     * гаснет (`ChangeOfferState::settleDeal`). До срока машина в «Предложениях», а все живые — в «Моих подтверждениях».
     * Сделку, что ему показывают (белый список, показ всем), он видит обычным предложением в «Предложениях» — здесь её нет,
     * пока показ идёт (05.11.2026).
     */
    public static function pending(User $me)
    {
        return self::waiting($me)->whereHas('offer', fn ($o) => $o->where('bids_close_at', '<=', now())
            ->whereNot(fn ($n) => $n->dealOpenTo($me)));
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
        // Параллельная (07.11.2026): шаг и путь — её теневые, шагов машины она не видит.
        Parallels::dress($deal);
        $position = $deal->offer->position(Track::Sale);
        // Журнал с начала сделки, с учётом откатов — тот же, что путь в CRM.
        $steps = Path::journal($deal->offer, Track::Sale, $deal->created_at, ! $deal->isActive() && $deal->wasParallel() ? $deal : null);
        // Менеджеру путь целиком: на развилке — главной дорогой (`guess`), а не обрывом на текущем шаге.
        $blocks = $position ? Path::ladder($position->stage, $steps->pluck('block')->all(), $deal, guess: true) : collect();

        return [
            'deal' => $deal,
            'offer' => $deal->offer,
            'position' => $position,
            'blocks' => $blocks,
            'steps' => $steps,
            'requirement' => $deal->openRequirement,
            'exits' => $deal->openRequirement ? ($deal->isParallel() && $position ? Parallels::exits($deal, $position->stage) : Handover::managerExits($position?->stage, $deal)) : collect(),
            'handover' => Handover::for($deal),
            // Из писем страховой — только то, что показал админ (`Offers\Disclosure`, 03.11.2026, владелец: «менеджеру вообще
            // ничего не показываем, пока админ не нажмёт»), новое сверху. Самих писем и их телефонов здесь нет.
            'disclosures' => Disclosures::shown($deal),
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
    public function receiver(Request $request, Deal $deal, SetReceiver $set)
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

    public function upload(Request $request, Deal $deal)
    {
        abort_unless($deal->buyer_id === $request->user()->id, 404);
        $requirement = $deal->openRequirement()->firstOrFail();
        $request->validate(['file' => ['required', 'file', 'max:30720']]);
        $file = $request->file('file');
        try {
            app(\App\Workflow\Actions\AttachRequirementFiles::class)($requirement, $file);
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
