<?php

namespace App\Http\Cabinet;

use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Live\Stream;
use App\Media\PhotoIngest;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Actions\PickUp;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Handover;
use App\Offers\InsurerReplies;
use App\Support\ListPrefs;
use App\Users\User;
use App\Support\Sort;
use App\Workflow\Actions\AnswerRequirement;
use App\Workflow\Actor;
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
     * Сделки одним экраном — только живое (11.10.2026, владелец: «не состоявшиеся в общем списке не вижу смысла»):
     * подтверждения без решения и сделки в работе. Закончившееся и не состоявшееся — в архиве (`?view=archive`), вход —
     * строкой «Архив N» в конце списка.
     */
    public function index(Request $request)
    {
        $me = $request->user();
        abort_unless($me->hasDeals() || $me->isAdmin(), 404);
        if ($request->query('view') === 'archive') {
            return $this->archive($request);
        }
        ListPrefs::sync($request, 'deals', view: false, keep: ['sort']);
        $sort = Sort::from($request->query('sort'), self::SORTS, '-fresh');
        // Гаражные сделки живут в «Гараже» (решение владельца 03.10.2026), здесь — только ждущее решения «В гараж».
        // Ход менеджера — просьба к нему или этап, где ждут его (ПРАЙМ без покупателя — без просьбы): просроченное, потом остальное.
        $open = 'requirements r where r.deal_id = deals.id and r.done_at is null';
        $turn = "offer_positions p join workflow_stages s on s.id = p.stage_id where p.offer_id = deals.offer_id and p.track = '".Track::Sale->value."' and coalesce(p.waits_for, s.waits_for) = '".WaitsFor::Manager->value."'";
        $deals = self::deals($me)->where('state', DealState::Active)
            ->orderByRaw("case when exists(select 1 from {$open} and r.due_at < now()) or exists(select 1 from {$turn} and p.deadline_at < now()) then 0"
                ." when exists(select 1 from {$open}) or exists(select 1 from {$turn}) then 1 else 2 end")
            ->orderByRaw("(select min(r.due_at) from {$open}) asc nulls last")
            ->when($sort->key === 'amount', fn ($d) => $d->orderByRaw("amount {$sort->dir()} nulls last"))->orderBy('created_at', $sort->key === 'fresh' ? $sort->dir() : 'desc')->orderByDesc('id')->paginate(30);

        // Ждущие решения подтверждения — будущие сделки; принятые уже стоят сделками. Админ подтверждения решает в CRM.
        $pending = $me->hasDeals() ? self::bids($me)->where('state', BidState::Active)->get() : collect();
        $archived = self::deals($me)->where('state', '!=', DealState::Active)->count() + ($me->hasDeals() ? self::lost($me)->count() : 0);

        return view('cabinet.deals.index', ['deals' => $deals, 'pending' => $pending, 'archived' => $archived, 'sort' => $sort]);
    }

    /** Архив: закончившиеся сделки и не состоявшиеся — отменённые сделки и отклонённые или отозванные подтверждения. */
    private function archive(Request $request)
    {
        $me = $request->user();
        $closed = self::deals($me)->where('state', '!=', DealState::Active)
            ->orderByRaw('coalesce(closed_at, updated_at) desc')->orderByDesc('id')->limit(self::ARCHIVE)->get();
        $lost = $me->hasDeals() ? self::lost($me)->limit(self::ARCHIVE)->get() : collect();
        // Не состоялось — одной лентой по дате: отменённая сделка и подтверждение, которое не выбрали.
        $failed = $closed->where('state', DealState::Cancelled)->concat($lost)
            ->sortByDesc(fn ($x) => ($x instanceof Deal ? ($x->closed_at ?? $x->updated_at) : ($x->decided_at ?? $x->updated_at))?->timestamp ?? 0)->values();

        return view('cabinet.deals.archive', ['done' => $closed->where('state', DealState::Done)->values(), 'failed' => $failed]);
    }

    /** Сделки экрана: без гаражных, с тем, что нужно строке. */
    private static function deals(User $me)
    {
        return Deal::with(['offer.brand', 'offer.model', 'offer.media', 'offer.positions.stage.block', 'openRequirement'])
            ->when(! $me->hasDeals(), fn ($d) => $d->with('buyer'))->of($me)->whereNull('garage_payer');
    }

    private static function bids(User $me)
    {
        return Bid::with(['offer.brand', 'offer.model', 'offer.media'])->where('user_id', $me->id)->latest();
    }

    /** Отклонённые и отозванные подтверждения — кроме тех, по которым была своя сделка: она уже в архиве строкой «Отменена». */
    private static function lost(User $me)
    {
        return self::bids($me)->whereIn('state', [BidState::Declined, BidState::Withdrawn])
            ->whereNotIn('id', Deal::where('buyer_id', $me->id)->whereNotNull('bid_id')->select('bid_id'));
    }

    public function show(Request $request, Deal $deal)
    {
        $me = $request->user();
        // Админ ведёт сделку в CRM: страница сделки на сайте — работа менеджера («Ваша доля», «Оплатить»).
        if ($me->isAdmin() && $deal->buyer_id !== $me->id) {
            return redirect()->away($deal->crmUrl());
        }
        abort_unless($deal->buyer_id === $me->id, 404);
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
        $deal->load(['offer.brand', 'offer.model', 'offer.media', 'offer.settlement', 'offer.positions.stage.block', 'offer.positions.stage.exits', 'openRequirement.media', 'requirements']);
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
            'exits' => $deal->openRequirement ? $position?->stage->exitsFor(Actor::Manager, $deal) : collect(),
            'handover' => Handover::for($deal),
            // Ответы страховой по сделке — текстом до подписи (05.10.2026), новые сверху.
            'replies' => InsurerReplies::for($deal),
            // Документы, что пришли с ответом (вложения, облако по ссылке, `ShareReplyFiles`), — под этим ответом.
            'replyFiles' => $deal->offer->papers()->filter(fn ($m) => $m->getCustomProperty('letter'))->groupBy(fn ($m) => $m->getCustomProperty('letter')),
            'invoices' => Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('state', '!=', InvoiceState::Void)->with('claims')->orderBy('id')->get(),
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

    /** «Автомобиль забрал» из карточки получения — у вывоза, когда шага продажи для этого нет (`Handover::servicePick`). */
    public function picked(Request $request, Deal $deal, PickUp $pickUp)
    {
        abort_unless($deal->buyer_id === $request->user()->id && $deal->isActive(), 404);
        abort_unless(Handover::for($deal)->servicePick, 422);
        $pickUp($deal->offer, $request->user());

        return redirect($deal->href())->with('toast', 'Автомобиль у вас');
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
        $requirement = $requirement->fresh();
        $stage = $deal->offer->position(Track::Sale)?->stage;

        return Stream::view('cabinet.deals.files-stream', ['requirement' => $requirement, 'deal' => $deal,
            'exits' => $stage ? $stage->exitsFor(Actor::Manager, $deal) : collect()]);
    }
}
